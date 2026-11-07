<?php

namespace App\Services\Line;

use App\Models\NotificationLog;
use App\Models\TaskReminder;
use App\Support\Owner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * 今月のLINE送信数（月の上限を超えないように、再通知を止めたり予約を制限したりする）
 *
 * ・アプリの数え方：通知履歴で「送信済み」になった数（今月・日本時間）。送り直しても1件は1通（同じ行を使うため）
 * ・LINE の数え方：公式の API（おおよその数。LINE Official Account Manager から送った分も含む）
 * ・使うのは2つのうち大きいほう
 * ・上限：LINE から取れたらその値（上限の設定がなければ「上限なし」）、取れなければ .env の LINE_MONTHLY_LIMIT（0 = 上限なし）
 * ・完了まで再通知は「上限 − 余裕（LINE_REMINDER_MARGIN）」で止める
 * ・スヌーズの新しい予約は、今月の送信数＋予約済みのスヌーズが上限を超えるおそれがあれば受け付けない
 *   LINE の数を取れないとき（アプリの数しかわからない）は、余裕をもって「上限 − 余裕」までにする
 * ・シミュレーション（LINE_DRIVER=log）は実際の送信ではないので数えず、何も止めない
 * ・数えるのは、いまのユーザー（App\Support\Owner）の分だけ（LINE公式アカウントは人ごとに別なので、上限も人ごと）
 */
class SendQuota
{
    private const CACHE_KEY = 'line.quota.official';

    public function __construct(private Messenger $messenger) {}

    /** アプリで数えた今月の送信数 */
    public function appCount(?CarbonImmutable $now = null): int
    {
        return $this->countThisMonth('sent', $now);
    }

    /** 今月のシミュレーションの数（実際の送信数には含めない） */
    public function simulatedCount(?CarbonImmutable $now = null): int
    {
        return $this->countThisMonth('simulated', $now);
    }

    private function countThisMonth(string $status, ?CarbonImmutable $now): int
    {
        $start = ($now ?? CarbonImmutable::now())->startOfMonth();

        return NotificationLog::query()->where('status', $status)
            ->where('sent_at', '>=', $start)->where('sent_at', '<', $start->addMonth())
            ->count();
    }

    /**
     * LINE側の数（10分間覚えておく。取れなかったときは覚えない）
     *
     * @return array{used: int, limit: ?int}|null limit が null = 上限の設定なし
     */
    public function official(): ?array
    {
        if (! $this->messenger->isLive()) {
            return null;
        }
        $key = self::CACHE_KEY.'.'.Owner::id().'.'.CarbonImmutable::now()->format('Y-m');
        if (($hit = Cache::get($key)) !== null) {
            return $hit;
        }
        $q = $this->messenger->quota();
        if ($q !== null) {
            Cache::put($key, $q, now()->addMinutes(10));
        }

        return $q;
    }

    /** LINE の数を取れたか */
    public function officialAvailable(): bool
    {
        return $this->official() !== null;
    }

    /** 判断に使う今月の送信数（アプリと LINE の大きいほう） */
    public function used(): int
    {
        return max($this->appCount(), $this->official()['used'] ?? 0);
    }

    /** 月の上限（null = 上限なし） */
    public function limit(): ?int
    {
        $o = $this->official();
        if ($o !== null) {
            return $o['limit'];
        }
        $c = (int) config('line.monthly_limit');

        return $c > 0 ? $c : null;
    }

    /** 完了まで再通知を止める通数（null = 止めない） */
    public function stopAt(): ?int
    {
        $limit = $this->limit();

        return $limit === null ? null : max(0, $limit - config('line.reminder_margin'));
    }

    /** 完了まで再通知を止めるか（実際に送る設定のときだけ） */
    public function remindersStopped(): bool
    {
        $stop = $this->stopAt();

        return $this->messenger->isLive() && $stop !== null && $this->used() >= $stop;
    }

    /** 今月の上限に達したか（スヌーズも送らない） */
    public function limitReached(): bool
    {
        $limit = $this->limit();

        return $this->messenger->isLive() && $limit !== null && $this->used() >= $limit;
    }

    /** これから送る予定の、今月のスヌーズの数 */
    public function pendingSnoozes(): int
    {
        $now = CarbonImmutable::now();

        return TaskReminder::query()->where('remind_at', '>', $now)->where('remind_at', '<', $now->startOfMonth()->addMonth())->count();
    }

    /**
     * スヌーズを新しく予約できない理由（予約できるなら null）
     * $at が来月なら、来月の数で判断する（月が替わると0から数えるので、ここでは止めない）
     */
    public function snoozeBlocked(CarbonImmutable $at): ?string
    {
        $limit = $this->limit();
        if (! $this->messenger->isLive() || $limit === null || ! $at->isSameMonth(CarbonImmutable::now())) {
            return null;
        }
        $used = $this->used();
        $pending = $this->pendingSnoozes();
        $sure = $this->officialAvailable();
        $cap = $sure ? $limit : $this->stopAt();
        if ($used + $pending + 1 <= $cap) {
            return null;
        }

        return "今月のLINE送信数が{$used}通、予約済みの再通知が{$pending}件あり、"
            .($sure ? "上限{$limit}通" : "LINE側の送信数を確認できないため、余裕をもった目安{$cap}通（上限{$limit}通）")
            .'を超えるおそれがあります。新しい再通知は予約できません（来月になると予約できます）';
    }
}
