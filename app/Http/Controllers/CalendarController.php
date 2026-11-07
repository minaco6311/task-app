<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Setting;
use App\Models\Task;
use App\Services\TaskLists;
use App\Services\TimelineLayout;
use App\Support\Jp;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** カレンダー（月・週・日・一覧） */
class CalendarController extends Controller
{
    public const VIEWS = ['month', 'week', 'day', 'list'];

    /** 一覧表示の日数 */
    public const LIST_DAYS = 7;

    public function index(Request $request): View
    {
        $view = in_array($request->query('view'), self::VIEWS, true)
            ? $request->query('view')
            : session('calendar.view', 'month');
        session(['calendar.view' => $view]); // 最後に見ていた表示を覚えておく

        $date = Jp::parseDate($request->query('date'));
        $today = Jp::today();

        $data = [
            'view' => $view,
            'date' => $date,
            'today' => $today,
            'categories' => Category::ordered(),
            'period' => $this->periodLabel($view, $date),
            'prev' => $this->shift($view, $date, -1, $today),
            'next' => $this->shift($view, $date, 1, $today),
            'gcalTask' => session('gcal_task') ? Task::find(session('gcal_task')) : null,
        ];

        return view('calendar.index', $data + match ($view) {
            'month' => $this->month($date, $today, $request->query('overdue') === 'all'),
            'list' => ['listDays' => (new TaskLists($today))->days($date, self::LIST_DAYS)],
            default => $this->timeline($view === 'week' ? $date->startOfWeek(CarbonInterface::SUNDAY) : $date, $view === 'week' ? 7 : 1, $today),
        });
    }

    /**
     * 月表示：カレンダーのマス（日曜はじまり）、選んだ日の予定一覧、今日やること・期限切れ
     *
     * @param  bool  $allOverdue  期限切れを14日より前も含めて出す
     */
    private function month(CarbonImmutable $date, CarbonImmutable $today, bool $allOverdue): array
    {
        $lists = new TaskLists($today);
        $first = $date->startOfMonth();
        $start = $first->subDays($first->dayOfWeek);
        $rows = (int) ceil(($first->dayOfWeek + $first->daysInMonth) / 7);
        $byDate = $this->tasksBetween($start, $start->addDays($rows * 7 - 1));

        $days = [];
        for ($i = 0; $i < $rows * 7; $i++) {
            $d = $start->addDays($i);
            $days[] = ['date' => $d, 'tasks' => $byDate->get($d->format('Y-m-d'), collect())];
        }

        $isToday = $date->isSameDay($today);

        return [
            'days' => $days,
            // 選んだ日が今日なら、この一覧が「今日やること」を兼ねるので優先度順にする
            'agenda' => $isToday ? $lists->today() : ($byDate->get($date->format('Y-m-d'))?->load('subtasks') ?? collect()),
            'showTitles' => (bool) Setting::values()['month_show_titles'],
            // 選んだ日が今日なら、今日の予定は上の一覧と同じなので取らない
            'todayTasks' => $isToday ? collect() : $lists->today(),
            'overdue' => $lists->overdue($allOverdue),
            'progress' => $lists->progress(),
        ];
    }

    /** 週・日表示：タイムライン */
    private function timeline(CarbonImmutable $start, int $n, CarbonImmutable $today): array
    {
        $byDate = $this->tasksBetween($start, $start->addDays($n - 1));
        $cols = [];
        for ($i = 0; $i < $n; $i++) {
            $d = $start->addDays($i);
            $list = $byDate->get($d->format('Y-m-d'), collect());
            $cols[] = [
                'date' => $d,
                'allDay' => $list->where('all_day', true)->values(),
                'events' => TimelineLayout::lanes($list->where('all_day', false)->filter(fn (Task $t) => $t->start)),
            ];
        }
        $now = CarbonImmutable::now();

        return [
            'cols' => $cols,
            'hasAllDay' => collect($cols)->contains(fn ($c) => $c['allDay']->isNotEmpty()),
            'nowMin' => $now->hour * 60 + $now->minute,
            'scrollHour' => collect($cols)->contains(fn ($c) => $c['date']->isSameDay($today)) ? max(0, $now->hour - 1) : 7,
        ];
    }

    /** @return Collection<string, Collection<int, Task>> */
    private function tasksBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Task::query()
            ->with('category')
            ->where('date', '>=', $from->format('Y-m-d'))
            ->where('date', '<', $to->addDay()->format('Y-m-d'))
            ->inDayOrder()
            ->get()
            ->groupBy(fn (Task $t) => $t->date->format('Y-m-d'));
    }

    private function periodLabel(string $view, CarbonImmutable $d): string
    {
        if ($view === 'month') {
            return "{$d->year}年{$d->month}月";
        }
        if ($view === 'day') {
            return Jp::dateLabel($d);
        }
        if ($view === 'list') {
            $b = $d->addDays(self::LIST_DAYS - 1);

            return "{$d->month}/{$d->day} – {$b->month}/{$b->day}";
        }
        $a = $d->startOfWeek(CarbonInterface::SUNDAY);
        $b = $a->addDays(6);

        return $a->month === $b->month
            ? "{$a->month}月{$a->day}日 – {$b->day}日"
            : "{$a->month}/{$a->day} – {$b->month}/{$b->day}";
    }

    /** 前へ・次へで移動する先の日付 */
    private function shift(string $view, CarbonImmutable $d, int $dir, CarbonImmutable $today): string
    {
        if ($view === 'month') {
            $m = $d->startOfMonth()->addMonths($dir);

            // 今月に戻ったときは今日を選ぶ
            return ($m->isSameMonth($today) ? $today : $m)->format('Y-m-d');
        }

        return $d->addDays($dir * match ($view) {
            'week' => 7,
            'list' => self::LIST_DAYS,
            default => 1,
        })->format('Y-m-d');
    }
}
