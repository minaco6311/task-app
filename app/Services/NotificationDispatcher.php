<?php

namespace App\Services;

use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Services\Line\Messenger;
use App\Services\Line\SendQuota;
use App\Services\Line\SendResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 時刻になった通知を送る（スケジューラーが毎分呼ぶ：php artisan notifications:send）
 *
 * ・二重送信の防止：通知ごとのキー（dedupe_key）を一意にして、送る前に履歴へ登録する
 * ・見送り：送る時刻を grace_minutes 以上過ぎたもの（サーバーが止まっていたなど）は送らずに「見送り」と記録
 * ・取り消し：送り直す前に予定を計算し直し、日時の変更・削除・通知のオフ・完了があれば送らない
 * ・再試行：LINE側の一時的なエラーと通信エラーだけ、最初と同じ本文・同じリトライキーで送り直す
 */
class NotificationDispatcher
{
    public const LAST_RUN_KEY = 'notifications.last_run';

    public function __construct(private Messenger $messenger) {}

    /** この実行の中で、自動の再通知を止めるかどうか（LINE への問い合わせを1回にする） */
    private ?bool $remindersStopped = null;

    /**
     * 時刻になった通知を送る（state: done / not_connected）
     *
     * @return array{state:string, results:list<array{key:string, type:string, at:string, action:string, note:?string}>}
     */
    public function run(?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        $now ??= CarbonImmutable::now();
        $this->remindersStopped = null;

        if ($this->messenger->isLive() && ! LineConnection::current()) {
            // 本番の送り方なのにLINEが未連携：何もしない（あとで連携したら、それ以降の通知から送る）
            if (! $dryRun) {
                Cache::forever(self::LAST_RUN_KEY, $now->toIso8601String());
            }

            return ['state' => 'not_connected', 'results' => []];
        }

        $grace = config('line.grace_minutes');
        $since = $now->subHours(config('line.lookback_hours'));
        $resumed = Setting::notificationsResumedAt();
        if ($resumed && ($r = CarbonImmutable::parse($resumed))->gt($since)) {
            $since = $r;
        }

        $items = collect((new NotificationPlanner(Setting::values()))->between($since, $now))->keyBy('key');
        $results = [];

        // 1) 失敗した通知・送信中のまま止まった通知を送り直す
        foreach ($this->retryTargets($now) as $log) {
            $results[] = $this->retry($log, $items->get($log->dedupe_key), $now, $dryRun);
        }

        // 2) 新しく時刻になった通知
        $known = NotificationLog::query()->whereIn('dedupe_key', $items->keys())->pluck('dedupe_key')->flip();
        foreach ($items as $key => $item) {
            if ($known->has($key)) {
                continue;
            }
            $results[] = $this->first($item, $now, $grace, $dryRun);
        }

        if (! $dryRun) {
            Cache::forever(self::LAST_RUN_KEY, $now->toIso8601String());
        }

        return ['state' => 'done', 'results' => array_values(array_filter($results))];
    }

    /** 初めて扱う通知 */
    private function first(array $item, CarbonImmutable $now, int $grace, bool $dryRun): ?array
    {
        $skip = match (true) {
            $item['at']->lt($now->subMinutes($grace)) => "送る時刻を{$grace}分以上過ぎていたため見送りました",
            $item['type'] === 'before' && $item['starts_at']->lte($now) => '予定の開始時刻を過ぎていたため見送りました',
            $item['covered'] => '同じ時刻に同じ予定の通知があるため、再通知は省略しました',
            $item['type'] === 'nag' => $this->nagBlocked($item, $now),
            $item['type'] === 'snooze' => $this->snoozeBlocked(),
            default => null,
        };

        if ($dryRun) {
            return $this->result($item['key'], $item['type'], $item['at'], $skip ? 'skip' : 'send', $skip);
        }

        try {
            $log = NotificationLog::query()->create([
                'dedupe_key' => $item['key'],
                'type' => $item['type'],
                'task_id' => $item['task_id'],
                'scheduled_at' => $item['at'],
                'status' => $skip ? 'skipped' : 'pending',
                'retry_key' => $skip ? null : (string) Str::uuid(), // 最初の送信からリトライキーを付ける
                'signature' => $item['signature'],
                'message' => NotificationPlanner::text($item, $now),
                'last_error' => $skip,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // ほかの処理が先に登録した（二重送信を防げた）
        }

        if ($skip) {
            return $this->result($log->dedupe_key, $log->type, $log->scheduled_at, 'skip', $skip);
        }

        return $this->deliver($log, $now);
    }

    /**
     * 完了まで再通知を送らない理由（送ってよければ null）
     * ・22時〜7時（遅れて送る場合も含む）
     * ・上限（1日3回・合計5回。通常の通知・スヌーズは数えない）
     * ・今月のLINE送信数が上限の手前（180通）に達した（シミュレーションのときは止めない）
     */
    private function nagBlocked(array $item, CarbonImmutable $now): ?string
    {
        $c = config('line.nag');
        if ($now->hour >= $c['quiet_from'] || $now->hour < $c['quiet_until']) {
            return "{$c['quiet_from']}時〜{$c['quiet_until']}時のため、再通知を送りませんでした";
        }
        $counted = fn () => NotificationLog::query()->where('type', 'nag')->where('task_id', $item['task_id'])
            ->whereIn('status', ['sent', 'simulated', 'pending', 'failed']);
        if ($counted()->count() >= $c['total']) {
            return "再通知の上限（合計{$c['total']}回）に達しました";
        }
        $day = $item['at']->startOfDay();
        if ($counted()->where('scheduled_at', '>=', $day)->where('scheduled_at', '<', $day->addDay())->count() >= $c['per_day']) {
            return "再通知の上限（1日{$c['per_day']}回）に達しました";
        }
        $quota = new SendQuota($this->messenger);
        $this->remindersStopped ??= $quota->remindersStopped();
        if ($this->remindersStopped) {
            return '今月のLINE送信数が'.$quota->stopAt().'通に達したため、完了までの再通知を止めました';
        }

        return null;
    }

    /** スヌーズを送らない理由（今月の上限に達したときだけ。予約のときにも上限は確かめている） */
    private function snoozeBlocked(): ?string
    {
        $quota = new SendQuota($this->messenger);

        return $quota->limitReached() ? '今月のLINE送信数が上限（'.$quota->limit().'通）に達したため、再通知を送りませんでした' : null;
    }

    /** @return iterable<NotificationLog> */
    private function retryTargets(CarbonImmutable $now): iterable
    {
        return NotificationLog::query()
            ->where('type', '!=', 'test')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', 'failed')->whereNotNull('next_retry_at')->where('next_retry_at', '<=', $now))
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('updated_at', '<=', $now->subMinutes(config('line.stale_pending_minutes')))))
            ->orderBy('scheduled_at')
            ->get();
    }

    private function retry(NotificationLog $log, ?array $item, CarbonImmutable $now, bool $dryRun): array
    {
        $giveUp = function (string $status, string $why) use ($log, $dryRun) {
            if (! $dryRun) {
                $log->forceFill(['status' => $status, 'next_retry_at' => null, 'last_error' => $why])->save();
            }

            return $this->result($log->dedupe_key, $log->type, $log->scheduled_at, $status === 'canceled' ? 'cancel' : 'give_up', $why);
        };

        // 予定を消した・日時を変えた・通知をオフにした・完了にした → この通知はもう無いので送らない
        if (! $item) {
            return $giveUp('canceled', '予定が変更・削除されたため、送り直しを取り消しました');
        }
        if ($log->scheduled_at->lt($now->subMinutes(config('line.grace_minutes')))
            || ($item['type'] === 'before' && $item['starts_at']->lte($now))) {
            return $giveUp('failed', '送り直しの期限を過ぎたため、送るのをやめました');
        }

        if ($dryRun) {
            return $this->result($log->dedupe_key, $log->type, $log->scheduled_at, 'retry', null);
        }

        // 中身が変わった（タイトルの変更、まとめに予定が増えた・減ったなど）
        // LINEの決まりでは、同じリトライキーで内容を変えて送ってはいけない → 新しい本文・新しいキーで送る
        if ($item['signature'] !== $log->signature) {
            $log->forceFill([
                'retry_key' => (string) Str::uuid(),
                'scheduled_at' => $item['at'],
                'signature' => $item['signature'],
                'message' => NotificationPlanner::text($item, $now),
                'attempts' => 0,
            ])->save();
        }

        return $this->deliver($log, $now);
    }

    /** 送って結果を記録する（送り直しも、最初と同じ本文・同じリトライキー） */
    private function deliver(NotificationLog $log, CarbonImmutable $now): array
    {
        $log->forceFill(['status' => 'pending', 'attempts' => $log->attempts + 1])->save();
        $res = $this->messenger->send($log->message, $log->retry_key);

        if ($res->ok()) {
            $log->forceFill(['status' => $res->status, 'sent_at' => $now, 'next_retry_at' => null, 'last_error' => null])->save();

            return $this->result($log->dedupe_key, $log->type, $log->scheduled_at, $res->status, null);
        }

        $again = $res->retryable && $log->attempts < config('line.max_attempts');
        $log->forceFill([
            'status' => 'failed',
            'next_retry_at' => $again ? $now->addMinutes(2 ** ($log->attempts - 1)) : null, // 1分 → 2分 → 4分
            'last_error' => $res->error,
        ])->save();
        $this->noteAuthError($res);

        return $this->result($log->dedupe_key, $log->type, $log->scheduled_at, $again ? 'retry_later' : 'failed', $res->error);
    }

    private function noteAuthError(SendResult $res): void
    {
        if ($res->authError) {
            LineConnection::current()?->markError($res->error);
        }
    }

    private function result(string $key, string $type, CarbonImmutable $at, string $action, ?string $note): array
    {
        return ['key' => $key, 'type' => $type, 'at' => $at->format('Y-m-d H:i'), 'action' => $action, 'note' => $note];
    }
}
