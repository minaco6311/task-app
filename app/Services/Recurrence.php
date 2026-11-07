<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskSeries;
use App\Models\TaskSeriesSkip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 繰り返し予定
 * ・各回は普通の予定（tasks）として、今日から90日先まで作る（毎日の自動処理で先へ延ばす）
 * ・同じ繰り返しの同じ日の回は、データベースの一意制約（series_id, series_date）で1つだけ
 * ・「この予定だけ削除」した回は task_series_skips に残し、作り直さない
 * ・「この予定以後すべて」の変更は、今ある回をできるだけ書き換えて使う（予定の ID が変わらないので、送った通知が二重に送られない）
 */
class Recurrence
{
    public const HORIZON_DAYS = 90;

    /** ルールに合う日付（from 〜 to、両端を含む） @return list<string> */
    public static function dates(TaskSeries $s, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->max($s->starts_on)->startOfDay();
        $end = $s->until ? $to->min($s->until) : $to;
        $weekdays = $s->weekdayList();
        $out = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $ok = match ($s->freq) {
                'daily' => true,
                'weekdays' => $d->isWeekday(),
                'weekly' => in_array($d->dayOfWeek, $weekdays, true),
                // 基準日（例：31日）がない月は、その月の末日
                'monthly' => $d->day === min((int) $s->month_day, $d->daysInMonth),
                default => false,
            };
            if ($ok) {
                $out[] = $d->format('Y-m-d');
            }
        }

        return $out;
    }

    /**
     * まだない回を作る（今日から90日先まで。終わりの日があればそこまで）
     *
     * @return int 作った件数
     */
    public static function fill(TaskSeries $s, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $horizon = $today->addDays(self::HORIZON_DAYS);
        $have = $s->tasks()->whereNotNull('series_date')->pluck('series_date')->map(fn ($d) => CarbonImmutable::parse($d)->format('Y-m-d'))->flip();
        $skip = TaskSeriesSkip::query()->where('series_id', $s->id)->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->format('Y-m-d'))->flip();

        $made = 0;
        foreach (self::dates($s, $today, $horizon) as $date) {
            if ($have->has($date) || $skip->has($date)) {
                continue;
            }
            try {
                DB::transaction(fn () => self::makeOccurrence($s, $date));
                $made++;
            } catch (UniqueConstraintViolationException) {
                // ほかの処理が同じ回を先に作った（二重に作らない）
            }
        }
        $s->forceFill(['generated_until' => $s->until ? $horizon->min($s->until) : $horizon])->save();

        return $made;
    }

    /** すべての繰り返しで、まだない回を作る（毎日の自動処理） */
    public static function fillAll(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $made = 0;
        TaskSeries::query()
            ->where(fn ($q) => $q->whereNull('until')->orWhereDate('until', '>=', $today->format('Y-m-d')))
            ->each(function (TaskSeries $s) use ($today, &$made) {
                $made += self::fill($s, $today);
            });

        return $made;
    }

    /** 1回分の予定を作る（チェック項目もコピー。完了は回ごと） */
    private static function makeOccurrence(TaskSeries $s, string $date): Task
    {
        $task = Task::create([
            ...$s->only(TaskSeries::TEMPLATE),
            'date' => $date,
            'series_id' => $s->id,
            'series_date' => $date,
        ]);
        foreach (array_values($s->subtasks ?? []) as $i => $title) {
            $task->subtasks()->create(['title' => $title, 'done' => false, 'sort_order' => $i]);
        }

        return $task;
    }

    /**
     * 繰り返しでない予定を、繰り返しにする（この予定が1回目）
     *
     * @param  array{freq: string, weekdays: list<int>, until: ?string}  $rule
     */
    public static function start(Task $task, array $rule): TaskSeries
    {
        $s = TaskSeries::query()->create(self::seriesData($task, $rule));
        $task->forceFill(['series_id' => $s->id, 'series_date' => $task->date, 'series_modified' => false])->save();
        self::fill($s);

        return $s;
    }

    /**
     * この回より後で「この予定だけ」変更した回
     *
     * @return Collection<int, Task>
     */
    public static function modifiedAfter(Task $task): Collection
    {
        if (! $task->series_id) {
            return new Collection;
        }

        return Task::query()
            ->where('series_id', $task->series_id)->where('series_modified', true)
            ->whereDate('series_date', '>', ($task->series_date ?? $task->date)->format('Y-m-d'))
            ->whereKeyNot($task->id)
            ->orderBy('series_date')->get();
    }

    /**
     * 「この予定以後すべて」を変更（$task はフォームの内容で更新済みの、この回）
     * ・前の繰り返しは、この回の前の日で終わりにする（過去の回と履歴はそのまま）
     * ・この回から新しい繰り返しを始める。$rule が null なら繰り返しをやめて、この回だけ残す
     * ・この回より後の回は、新しいルールに合う日なら書き換えて使い（完了の状態は残す）、合わなければ消す
     * ・「この予定だけ」変更した回は、$overwriteModified が false（標準）なら内容を残す
     *   （新しいルールに合う日なら新しい繰り返しの回として、合わなければ繰り返しから外した普通の予定として残す）
     * ・「この予定だけ削除」した回の印は、新しい繰り返しにも引き継ぐ（消した回を復活させない）
     *
     * @return array{series: ?TaskSeries, kept: int, detached: int, overwritten: int}
     */
    public static function updateFollowing(Task $task, ?array $rule, bool $overwriteModified = false): array
    {
        $old = $task->series;
        $cut = $task->series_date ?? $task->date;
        $old->forceFill(['until' => $cut->subDay()])->save();
        $later = $old->tasks()->whereDate('series_date', '>', $cut->format('Y-m-d'))->whereKeyNot($task->id)->get();
        $stats = ['series' => null, 'kept' => 0, 'detached' => 0, 'overwritten' => 0];
        $detach = function (Task $t) use (&$stats) {
            $t->update(['series_id' => null, 'series_date' => null, 'series_modified' => false]);
            $stats['detached']++;
        };

        if (! $rule) {
            foreach ($later as $t) {
                $t->series_modified && ! $overwriteModified ? $detach($t) : $t->delete();
            }
            $task->forceFill(['series_id' => null, 'series_date' => null, 'series_modified' => false])->save();

            return $stats;
        }

        $new = TaskSeries::query()->create(self::seriesData($task->refresh(), $rule));
        $task->forceFill(['series_id' => $new->id, 'series_date' => $task->date, 'series_modified' => false])->save();
        $stats['series'] = $new;

        // 「この予定だけ削除」した日を引き継ぐ
        $old->skips()->whereDate('date', '>', $cut->format('Y-m-d'))->get()
            ->each(fn (TaskSeriesSkip $k) => TaskSeriesSkip::query()->firstOrCreate(['series_id' => $new->id, 'date' => $k->date->format('Y-m-d')]));

        $fits = array_flip(self::dates($new, $new->starts_on, CarbonImmutable::today()->addDays(self::HORIZON_DAYS)));
        unset($fits[$task->date->format('Y-m-d')]); // この回の日は、この回が使う
        foreach ($later as $t) {
            $date = $t->series_date->format('Y-m-d');
            $fit = isset($fits[$date]);
            if ($t->series_modified && ! $overwriteModified) {
                if ($fit) {
                    unset($fits[$date]);
                    $t->update(['series_id' => $new->id]); // 中身はそのまま、新しい繰り返しの回として残す
                    $stats['kept']++;
                } else {
                    $detach($t);
                }

                continue;
            }
            if (! $fit) {
                $t->delete();

                continue;
            }
            unset($fits[$date]);
            $stats['overwritten'] += $t->series_modified ? 1 : 0;
            $t->update([...$new->only(TaskSeries::TEMPLATE), 'date' => $date, 'series_id' => $new->id, 'series_date' => $date, 'series_modified' => false]);
            self::syncSubtasks($t, $new->subtasks ?? []);
            $t->reminders()->where('remind_at', '>', now())->delete(); // 内容が変わったので、前のスヌーズは取り消す
        }
        self::fill($new);

        return $stats;
    }

    /** 「この予定だけ削除」：消した回を作り直さないように印を残す */
    public static function deleteOnly(Task $task): void
    {
        if ($task->series_id && $task->series_date) {
            TaskSeriesSkip::query()->firstOrCreate(['series_id' => $task->series_id, 'date' => $task->series_date->format('Y-m-d')]);
        }
        $task->delete();
    }

    /** 「この予定以後すべて削除」：この回から後を消し、繰り返しを前の日で終わりにする（過去の回と履歴はそのまま） */
    public static function deleteFollowing(Task $task): void
    {
        $s = $task->series;
        $cut = $task->series_date ?? $task->date;
        $s->forceFill(['until' => $cut->subDay()])->save();
        $s->tasks()->whereDate('series_date', '>=', $cut->format('Y-m-d'))->get()->each->delete();
        $task->delete(); // この回の日付を動かしていても消す
    }

    /** 回のチェック項目を、新しい名前の並びにそろえる（同じ名前の項目は完了の状態を残す） */
    private static function syncSubtasks(Task $task, array $titles): void
    {
        $old = $task->subtasks()->get()->groupBy('title');
        $keep = [];
        foreach (array_values($titles) as $i => $title) {
            $sub = $old->get($title)?->shift();
            if ($sub) {
                $sub->update(['sort_order' => $i]);
                $keep[] = $sub->id;
            } else {
                $keep[] = $task->subtasks()->create(['title' => $title, 'done' => false, 'sort_order' => $i])->id;
            }
        }
        $task->subtasks()->whereNotIn('id', $keep)->delete();
    }

    private static function seriesData(Task $task, array $rule): array
    {
        return [
            ...$task->only(TaskSeries::TEMPLATE),
            'freq' => $rule['freq'],
            'weekdays' => $rule['freq'] === 'weekly' ? implode(',', $rule['weekdays']) : null,
            'month_day' => $rule['freq'] === 'monthly' ? $task->date->day : null,
            'starts_on' => $task->date->format('Y-m-d'),
            'until' => $rule['until'],
            'subtasks' => $task->subtasks()->pluck('title')->all(),
        ];
    }
}
