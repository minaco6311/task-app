<?php

namespace App\Services;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 一覧に出す予定をそろえる
 * ・今日やること（今日の予定）。優先度の高い順
 * ・期限切れ（今日より前の日付で、まだ完了していない予定）。標準は過去14日間。優先度の高い順
 * ・直近7日間（一覧表示）。日時の順（優先度では並べ替えない）
 * ・今日の達成状況
 */
class TaskLists
{
    /** 期限切れの標準の範囲（日） */
    public const OVERDUE_DAYS = 14;

    /** 期限切れを一度に表示する上限（多くても画面が重くならないように） */
    public const OVERDUE_LIMIT = 100;

    public function __construct(private CarbonImmutable $today) {}

    /** 今日の予定（優先度の高い順 → 同じ優先度なら 終日 → 時刻の早い順） */
    public function today(): Collection
    {
        return $this->todayQuery()->with(['category', 'subtasks'])
            ->byPriority()->inDayOrder()
            ->get();
    }

    private function todayQuery(): Builder
    {
        return Task::query()
            ->where('date', '>=', $this->today->format('Y-m-d'))
            ->where('date', '<', $this->today->addDay()->format('Y-m-d'));
    }

    /**
     * 今日の達成状況（今日の日付の予定だけ。期限切れ・サブタスク・明日以降は数えない）
     *
     * @return array{done: int, total: int, percent: int}
     */
    public function progress(): array
    {
        $total = $this->todayQuery()->count();
        $done = $this->todayQuery()->where('done', true)->count();

        return ['done' => $done, 'total' => $total, 'percent' => $total ? (int) round($done / $total * 100) : 0];
    }

    /** 期限切れの条件（all = 14日より前も含める） */
    public function overdueQuery(bool $all): Builder
    {
        return Task::query()
            ->where('done', false)
            ->where('date', '<', $this->today->format('Y-m-d'))
            ->when(! $all, fn (Builder $q) => $q->where('date', '>=', $this->overdueFrom()->format('Y-m-d')));
    }

    /**
     * 期限切れ（count = 対象の件数、older = 14日より前にある未完了の件数、tasks は表示する分だけ）
     *
     * @return array{tasks: Collection<int, Task>, count: int, older: int, all: bool, limit: int}
     */
    public function overdue(bool $all): array
    {
        $count = $this->overdueQuery($all)->count();
        // 優先度の高い順 → 同じ優先度なら日時の早い順
        $tasks = $this->overdueQuery($all)->with(['category', 'subtasks'])
            ->byPriority()->orderBy('date')->inDayOrder()
            ->limit(self::OVERDUE_LIMIT)
            ->get();
        $older = Task::query()->where('done', false)->where('date', '<', $this->overdueFrom()->format('Y-m-d'))->count();

        return ['tasks' => $tasks, 'count' => $count, 'older' => $older, 'all' => $all, 'limit' => self::OVERDUE_LIMIT];
    }

    /** 期限切れの標準の範囲の最初の日（今日の14日前） */
    public function overdueFrom(): CarbonImmutable
    {
        return $this->today->subDays(self::OVERDUE_DAYS);
    }

    /**
     * from から n 日間の予定（日付ごと・時刻順）。予定のない日も入れる
     *
     * @return list<array{date: CarbonImmutable, tasks: Collection<int, Task>}>
     */
    public function days(CarbonImmutable $from, int $n = 7): array
    {
        $byDate = Task::query()->with(['category', 'subtasks'])
            ->where('date', '>=', $from->format('Y-m-d'))
            ->where('date', '<', $from->addDays($n)->format('Y-m-d'))
            ->orderBy('date')->inDayOrder()
            ->get()
            ->groupBy(fn (Task $t) => $t->date->format('Y-m-d'));

        $days = [];
        for ($i = 0; $i < $n; $i++) {
            $d = $from->addDays($i);
            $days[] = ['date' => $d, 'tasks' => $byDate->get($d->format('Y-m-d'), collect())];
        }

        return $days;
    }
}
