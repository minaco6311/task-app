<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskReminder;
use App\Support\Jp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * LINE通知の予定を計算する（設定画面の見本と、実際の送信の両方で使う）
 * ・前日／当日朝：その日の予定をまとめて1通
 * ・開始前：予定ごとに1通（終日・開始時刻のない予定には送らない）
 * ・再通知（スヌーズ）：利用者が予約した時刻に1通
 * ・完了まで再通知：開始時刻から1時間おき・1日3回・合計5回まで。22時〜7時は送らない
 * ・完了にした予定は通知しない
 * ・再通知が、同じ予定を含む通常の通知（前日・当日朝・開始前）と同じ時刻なら「省略」（covered）にする
 *
 * 通知はその場で予定から計算するので、日時の変更・削除・通知のオフはすぐ反映される（前もって送信を予約しない）
 */
class NotificationPlanner
{
    /** 同じ時刻の通知を並べる順番 */
    private const ORDER = ['day_before' => 0, 'morning' => 1, 'before' => 2, 'snooze' => 3, 'nag' => 4];

    /** まとめの通知に載せる予定の上限（LINEの文字数の上限に収めるため） */
    private const MAX_LINES = 30;

    public function __construct(private array $settings) {}

    /**
     * これから届く通知（設定画面の見本）
     *
     * @return list<array> 各要素は item() の形
     */
    public function upcoming(int $days = 7, int $limit = 12): array
    {
        $now = CarbonImmutable::now();
        $today = Jp::today();
        $all = $this->withReminders($this->forDates($today, $today->addDays($days)), $now, $today->addDays($days + 1));
        $res = array_filter($all, fn ($r) => $r['at']->gte($now) && ! $r['covered']);

        return array_slice(array_values($res), 0, $limit);
    }

    /**
     * 送る時刻が from 〜 to（両端を含む）の通知
     *
     * @return list<array>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // 前日の通知は前の日に、開始前の通知は最大3時間前に届くので、対象の日は1日ずつ広めに見る
        $res = $this->withReminders($this->forDates($from->startOfDay(), $to->startOfDay()->addDay()), $from, $to);

        return array_values(array_filter($res, fn ($r) => $r['at']->gte($from) && $r['at']->lte($to)));
    }

    /**
     * 通常の通知に、再通知（スヌーズ・完了まで再通知）を足して時刻順にする
     * 同じ時刻に同じ予定の通常の通知があれば、再通知は covered（省略）にする。スヌーズと完了まで再通知が重なったら、完了まで再通知を省略
     */
    private function withReminders(array $normal, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $minute = fn (CarbonImmutable $at) => $at->format('Y-m-d H:i');
        $taken = [];
        foreach ($normal as $n) {
            foreach ($n['task_ids'] as $id) {
                $taken[$minute($n['at'])][$id] = true;
            }
        }

        $snoozes = $this->snoozes($from, $to);
        foreach ($snoozes as &$s) {
            $s['covered'] = isset($taken[$minute($s['at'])][$s['task_id']]);
        }
        unset($s);
        foreach ($snoozes as $s) {
            $taken[$minute($s['at'])][$s['task_id']] = true;
        }
        $nags = $this->nags($from, $to);
        foreach ($nags as &$g) {
            $g['covered'] = isset($taken[$minute($g['at'])][$g['task_id']]);
        }
        unset($g);

        $res = [...$normal, ...$snoozes, ...$nags];
        usort($res, fn ($a, $b) => [$a['at'], self::ORDER[$a['type']], $a['starts_at'], $a['key']]
            <=> [$b['at'], self::ORDER[$b['type']], $b['starts_at'], $b['key']]);

        return $res;
    }

    /** スヌーズ（利用者が予約した再通知） */
    private function snoozes(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return TaskReminder::query()->with('task')
            ->where('remind_at', '>=', $from)->where('remind_at', '<=', $to)
            ->get()
            ->filter(fn (TaskReminder $r) => $r->task && ! $r->task->done)
            ->map(fn (TaskReminder $r) => $this->item("snooze:{$r->id}", 'snooze', $r->task_id, $r->remind_at, $r->task->startsAt(),
                '🔁 再通知', [$this->taskLine($r->task)], [$r->task_id]))
            ->values()->all();
    }

    /** 完了まで再通知（オンにした未完了の予定） */
    private function nags(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $total = config('line.nag.total');
        $out = [];
        Task::query()
            ->where('remind_until_done', true)->where('done', false)
            ->where('date', '>=', $from->subDays(3)->format('Y-m-d')) // 再通知は開始の日から数日にまたがる
            ->where('date', '<', $to->addDay()->format('Y-m-d'))
            ->get()
            ->each(function (Task $t) use ($from, $to, $total, &$out) {
                foreach ($this->nagTimes($t) as $n => $at) {
                    if ($at->gte($from) && $at->lte($to)) {
                        $out[] = $this->item("nag:{$t->id}:{$n}", 'nag', $t->id, $at, $t->startsAt(),
                            "🔁 まだ完了していません（再通知 {$n}/{$total}）", [$this->taskLine($t)], [$t->id]);
                    }
                }
            });

        return $out;
    }

    /**
     * 完了まで再通知の時刻（1回目 … 最大5回目）
     * 開始時刻（終日なら当日朝の通知の時刻）から1時間おき。1日3回まで。22時〜翌7時は送らず、翌朝7時から続ける
     *
     * @return array<int, CarbonImmutable>
     */
    public function nagTimes(Task $t): array
    {
        $c = config('line.nag');
        $base = $t->start ? $t->startsAt() : Jp::at($t->date, $this->settings['morning_time']);
        $at = $base->addMinutes($c['interval']);
        $times = [];
        $perDay = [];
        for ($guard = 0; count($times) < $c['total'] && $guard < 200; $guard++) {
            if ($at->hour >= $c['quiet_from']) {
                $at = $at->addDay()->setTime($c['quiet_until'], 0);

                continue;
            }
            if ($at->hour < $c['quiet_until']) {
                $at = $at->setTime($c['quiet_until'], 0);

                continue;
            }
            $day = $at->format('Y-m-d');
            if (($perDay[$day] ?? 0) >= $c['per_day']) {
                $at = $at->addDay()->setTime($c['quiet_until'], 0);

                continue;
            }
            $times[count($times) + 1] = $at;
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            $at = $at->addMinutes($c['interval']);
        }

        return $times;
    }

    /** 再通知の本文の1行（11月5日(木) 14:00〜 歯医者） */
    private function taskLine(Task $t): string
    {
        return Jp::dateLabel($t->date).' '.($t->all_day || ! $t->start ? '終日' : $t->start.'〜').' '.$t->title;
    }

    /**
     * 予定の日が first 〜 last の通知（時刻順）
     *
     * @return list<array{key:string, type:string, task_id:?int, at:CarbonImmutable, starts_at:?CarbonImmutable, head:string, lines:list<string>, signature:string}>
     */
    private function forDates(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $tasks = Task::query()
            ->where('date', '>=', $first->format('Y-m-d'))
            ->where('date', '<', $last->addDay()->format('Y-m-d')) // 「<= 最終日」だと SQLite で最終日が外れるため
            ->where('done', false)
            ->inDayOrder()
            ->get()
            ->groupBy(fn (Task $t) => $t->date->format('Y-m-d'));

        $res = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            /** @var Collection<int, Task> $list */
            $list = $tasks->get($date, collect());

            $db = $list->where('notify_day_before', true);
            if ($db->isNotEmpty()) {
                $res[] = $this->summary('day_before', $day, Jp::at($day->subDay(), $this->settings['day_before_time']),
                    '🌙 明日の予定（'.Jp::dateLabel($day).'）', $db);
            }
            $mo = $list->where('notify_morning', true);
            if ($mo->isNotEmpty()) {
                $res[] = $this->summary('morning', $day, Jp::at($day, $this->settings['morning_time']),
                    '☀️ 今日の予定（'.Jp::dateLabel($day).'）', $mo);
            }
            foreach ($list as $t) {
                if (! $t->all_day && $t->start && $t->notify_before_minutes > 0) {
                    $starts = $t->startsAt();
                    $line = "{$t->start}〜 {$t->title}";
                    $res[] = $this->item(
                        "before:{$t->id}:{$date} {$t->start}:{$t->notify_before_minutes}", 'before', $t->id,
                        $starts->subMinutes($t->notify_before_minutes), $starts,
                        '⏰ '.Jp::afterLabel($t->notify_before_minutes).'に始まります', [$line], [$t->id],
                    );
                }
            }
        }

        usort($res, fn ($a, $b) => [$a['at'], self::ORDER[$a['type']], $a['starts_at'], $a['key']]
            <=> [$b['at'], self::ORDER[$b['type']], $b['starts_at'], $b['key']]);

        return $res;
    }

    private function summary(string $type, CarbonImmutable $day, CarbonImmutable $at, string $head, Collection $tasks): array
    {
        $lines = $tasks->map(fn (Task $t) => ($t->all_day ? '終日' : $t->start).' '.$t->title)->values();
        if ($lines->count() > self::MAX_LINES) {
            $rest = $lines->count() - self::MAX_LINES + 1;
            $lines = $lines->take(self::MAX_LINES - 1)->push("ほか{$rest}件");
        }

        return $this->item("{$type}:{$day->format('Y-m-d')}", $type, null, $at, null, $head, $lines->all(), $tasks->pluck('id')->all());
    }

    /** @param list<int> $taskIds この通知に載っている予定（再通知との重なりを調べるため） */
    private function item(string $key, string $type, ?int $taskId, CarbonImmutable $at, ?CarbonImmutable $startsAt, string $head, array $lines, array $taskIds = []): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'task_id' => $taskId,
            'task_ids' => $taskIds,
            'covered' => false,
            'at' => $at,
            'starts_at' => $startsAt,
            'head' => $head,
            'lines' => $lines,
            // 中身（送る時刻・予定の日時とタイトル）が同じかを比べるための指紋
            'signature' => hash('sha256', json_encode([$key, $at->format('Y-m-d H:i'), $lines], JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * LINEに送る本文
     * 開始前の通知が遅れて送られるとき（サーバーが少し止まっていたなど）は、実際の残り時間を書く
     */
    public static function text(array $item, CarbonImmutable $now): string
    {
        $head = $item['head'];
        if ($item['type'] === 'before' && $item['starts_at'] && $now->gt($item['at'])) {
            $left = max(1, (int) ceil($now->diffInSeconds($item['starts_at'], false) / 60));
            $head = '⏰ '.Jp::afterLabel($left).'に始まります';
        }
        $body = $head."\n".implode("\n", array_map(fn ($l) => '・'.$l, $item['lines']));

        return mb_substr($body, 0, 5000); // テキストメッセージの上限
    }
}
