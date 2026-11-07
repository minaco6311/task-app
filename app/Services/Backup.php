<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Setting;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskSeries;
use App\Models\TaskSeriesSkip;
use App\Support\Jp;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * バックアップの書き出し・読み込み（JSON）
 * 読み込みは、このアプリの形式に加えて、試作版（prototype/）の書き出し形式にも対応
 */
class Backup
{
    public static function export(): array
    {
        return [
            'app' => 'irodori-techo',
            // 3 = 優先度・サブタスク、4 = 繰り返し予定・完了まで再通知（それより前のファイルも読み込める）
            // スヌーズの予約・通知履歴・LINE連携・ログイン情報は書き出さない
            'version' => 4,
            'exported_at' => now()->toIso8601String(),
            'settings' => Setting::values(),
            'categories' => Category::ordered()->map(fn (Category $c) => $c->only(['id', 'name', 'color', 'key', 'sort_order']))->all(),
            'series' => TaskSeries::query()->with('skips')->orderBy('id')->get()->map(fn (TaskSeries $s) => [
                'id' => $s->id,
                'freq' => $s->freq,
                'weekdays' => $s->weekdayList(),
                'month_day' => $s->month_day,
                'starts_on' => $s->starts_on->format('Y-m-d'),
                'until' => $s->until?->format('Y-m-d'),
                'title' => $s->title,
                'all_day' => $s->all_day,
                'start_time' => $s->start_time ? substr($s->start_time, 0, 5) : null,
                'end_time' => $s->end_time ? substr($s->end_time, 0, 5) : null,
                'category_id' => $s->category_id,
                'memo' => $s->memo,
                'priority' => $s->priority,
                'notify_day_before' => $s->notify_day_before,
                'notify_morning' => $s->notify_morning,
                'notify_before_minutes' => $s->notify_before_minutes,
                'remind_until_done' => $s->remind_until_done,
                'subtasks' => array_values($s->subtasks ?? []),
                'skips' => $s->skips->map(fn ($k) => $k->date->format('Y-m-d'))->values()->all(),
            ])->all(),
            'tasks' => Task::query()->with('subtasks')->orderBy('date')->inDayOrder()->get()->map(fn (Task $t) => [
                'title' => $t->title,
                'date' => $t->date->format('Y-m-d'),
                'all_day' => $t->all_day,
                'start_time' => $t->start ?: null,
                'end_time' => $t->end ?: null,
                'category_id' => $t->category_id,
                'memo' => $t->memo,
                'done' => $t->done,
                'notify_day_before' => $t->notify_day_before,
                'notify_morning' => $t->notify_morning,
                'notify_before_minutes' => $t->notify_before_minutes,
                'gcal_added' => $t->gcal_added,
                'priority' => $t->priority,
                'remind_until_done' => $t->remind_until_done,
                'series_id' => $t->series_id,
                'series_date' => $t->series_date?->format('Y-m-d'),
                'series_modified' => $t->series_modified,
                'subtasks' => $t->subtasks->map(fn ($s) => ['title' => $s->title, 'done' => $s->done, 'sort_order' => $s->sort_order])->all(),
            ])->all(),
        ];
    }

    /**
     * いまのデータをすべて置き換える
     *
     * @return int 読み込んだ予定の件数
     */
    public static function restore(array $data): int
    {
        if (! isset($data['tasks']) || ! is_array($data['tasks'])) {
            throw new InvalidArgumentException('tasks がありません');
        }
        $legacy = ($data['version'] ?? 1) < 2; // 試作版の形式

        return DB::transaction(function () use ($data, $legacy) {
            Task::query()->delete();        // チェック項目・スヌーズの予約も一緒に消える
            TaskSeries::query()->delete();  // 繰り返しの設定（削除した回の印も一緒に消える）
            Category::query()->delete();

            // カテゴリ（古いID → 新しいID）
            $idMap = [];
            $cats = is_array($data['categories'] ?? null) && $data['categories'] ? $data['categories'] : Category::DEFAULTS;
            foreach (array_values($cats) as $i => $c) {
                $color = preg_match('/^#[0-9a-fA-F]{6}$/', $c['color'] ?? '') ? $c['color'] : Category::PALETTE[$i % count(Category::PALETTE)];
                $key = $c['key'] ?? ($legacy && in_array($c['id'] ?? null, array_column(Category::DEFAULTS, 'key'), true) ? $c['id'] : null);
                $new = Category::create([
                    'name' => mb_substr((string) ($c['name'] ?? '無題'), 0, 20),
                    'color' => $color,
                    'key' => $key,
                    'sort_order' => $i,
                ]);
                $idMap[(string) ($c['id'] ?? $key ?? $i)] = $new->id;
            }

            // 設定
            $s = $data['settings'] ?? [];
            $settings = $legacy ? [
                'default_before' => $s['defaultBefore'] ?? null,
                'day_before_time' => $s['dayBeforeTime'] ?? null,
                'morning_time' => $s['morningTime'] ?? null,
            ] : $s;
            foreach (Setting::DEFAULTS as $k => $default) {
                Setting::put($k, $settings[$k] ?? $default);
            }
            // 予定を入れ直すと ID が変わるので、戻す前に送り終えた通知がもう一度送られないようにする
            Setting::markNotificationsResumed();

            // 繰り返しの設定（version 4 から。古い ID → 新しい ID）
            $seriesMap = self::restoreSeries(is_array($data['series'] ?? null) ? $data['series'] : [], $idMap);
            $usedSlots = [];

            // 予定
            $count = 0;
            foreach ($data['tasks'] as $t) {
                if (! is_array($t) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $t['date'] ?? '') || trim((string) ($t['title'] ?? '')) === '') {
                    continue;
                }
                $n = $t['notify'] ?? [];
                $row = $legacy ? [
                    'all_day' => (bool) ($t['allDay'] ?? false),
                    'start_time' => $t['start'] ?? null,
                    'end_time' => $t['end'] ?? null,
                    'category_id' => $idMap[(string) ($t['categoryId'] ?? '')] ?? null,
                    'notify_day_before' => (bool) ($n['dayBefore'] ?? true),
                    'notify_morning' => (bool) ($n['morning'] ?? true),
                    'notify_before_minutes' => (int) ($n['before'] ?? 60),
                    'gcal_added' => (bool) ($t['gcal'] ?? false),
                ] : [
                    'all_day' => (bool) ($t['all_day'] ?? false),
                    'start_time' => $t['start_time'] ?? null,
                    'end_time' => $t['end_time'] ?? null,
                    'category_id' => $idMap[(string) ($t['category_id'] ?? '')] ?? null,
                    'notify_day_before' => (bool) ($t['notify_day_before'] ?? true),
                    'notify_morning' => (bool) ($t['notify_morning'] ?? true),
                    'notify_before_minutes' => (int) ($t['notify_before_minutes'] ?? 60),
                    'gcal_added' => (bool) ($t['gcal_added'] ?? false),
                ];
                foreach (['start_time', 'end_time'] as $k) {
                    $row[$k] = Jp::toMin($row[$k]) === null ? null : Jp::fromMin(Jp::toMin($row[$k]));
                }
                $prio = (int) ($t['priority'] ?? Task::PRIORITY_NORMAL); // 前の形式には無い → 中

                // 繰り返しの回（同じ繰り返しの同じ日が2つあれば、2つ目は普通の予定として戻す）
                $seriesId = $seriesMap[(string) ($t['series_id'] ?? '')] ?? null;
                $slot = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($t['series_date'] ?? '')) ? $t['series_date'] : null;
                if (! $seriesId || ! $slot || isset($usedSlots["{$seriesId}:{$slot}"])) {
                    $seriesId = $slot = null;
                } else {
                    $usedSlots["{$seriesId}:{$slot}"] = true;
                }

                $task = Task::create(Task::normalizeTimes([
                    ...$row,
                    'title' => mb_substr(trim($t['title']), 0, 80),
                    'date' => $t['date'],
                    'memo' => isset($t['memo']) ? mb_substr((string) $t['memo'], 0, 500) : null,
                    'done' => (bool) ($t['done'] ?? false),
                    'priority' => array_key_exists($prio, Task::PRIORITIES) ? $prio : Task::PRIORITY_NORMAL,
                    'remind_until_done' => (bool) ($t['remind_until_done'] ?? false),
                    'series_id' => $seriesId,
                    'series_date' => $slot,
                    'series_modified' => $seriesId && (bool) ($t['series_modified'] ?? false),
                ]));

                // サブタスク（20個まで。並び順を保つ）
                $subs = collect(is_array($t['subtasks'] ?? null) ? $t['subtasks'] : [])
                    ->filter(fn ($s) => is_array($s) && trim((string) ($s['title'] ?? '')) !== '')
                    ->sortBy(fn ($s) => (int) ($s['sort_order'] ?? 0))
                    ->take(Subtask::MAX_PER_TASK)->values();
                foreach ($subs as $i => $s) {
                    $task->subtasks()->create(['title' => mb_substr(trim($s['title']), 0, 80), 'done' => (bool) ($s['done'] ?? false), 'sort_order' => $i]);
                }
                $count++;
            }

            return $count;
        });
    }

    /**
     * 繰り返しの設定を戻す
     *
     * @param  array<string, int>  $catMap  カテゴリの古い ID → 新しい ID
     * @return array<string, int> 繰り返しの古い ID → 新しい ID
     */
    private static function restoreSeries(array $list, array $catMap): array
    {
        $map = [];
        foreach ($list as $s) {
            $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
            if (! is_array($s) || ! isset(TaskSeries::FREQS[$s['freq'] ?? '']) || ! $date($s['starts_on'] ?? null) || trim((string) ($s['title'] ?? '')) === '') {
                continue;
            }
            $days = array_values(array_filter(array_map('intval', (array) ($s['weekdays'] ?? [])), fn ($d) => $d >= 0 && $d <= 6));
            $prio = (int) ($s['priority'] ?? Task::PRIORITY_NORMAL);
            $times = Task::normalizeTimes([
                'all_day' => (bool) ($s['all_day'] ?? false),
                'start_time' => Jp::toMin($s['start_time'] ?? null) === null ? null : Jp::fromMin(Jp::toMin($s['start_time'])),
                'end_time' => Jp::toMin($s['end_time'] ?? null) === null ? null : Jp::fromMin(Jp::toMin($s['end_time'])),
            ]);
            $new = TaskSeries::query()->create([
                ...$times,
                'freq' => $s['freq'],
                'weekdays' => $s['freq'] === 'weekly' ? implode(',', $days) : null,
                'month_day' => $s['freq'] === 'monthly' ? max(1, min(31, (int) ($s['month_day'] ?? 1))) : null,
                'starts_on' => $s['starts_on'],
                'until' => $date($s['until'] ?? null),
                'title' => mb_substr(trim($s['title']), 0, 80),
                'category_id' => $catMap[(string) ($s['category_id'] ?? '')] ?? null,
                'memo' => isset($s['memo']) ? mb_substr((string) $s['memo'], 0, 500) : null,
                'priority' => array_key_exists($prio, Task::PRIORITIES) ? $prio : Task::PRIORITY_NORMAL,
                'notify_day_before' => (bool) ($s['notify_day_before'] ?? true),
                'notify_morning' => (bool) ($s['notify_morning'] ?? true),
                'notify_before_minutes' => (int) ($s['notify_before_minutes'] ?? 60),
                'remind_until_done' => (bool) ($s['remind_until_done'] ?? false),
                'subtasks' => array_slice(array_values(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 80), (array) ($s['subtasks'] ?? [])), 'strlen')), 0, Subtask::MAX_PER_TASK),
            ]);
            foreach (array_unique(array_filter(array_map($date, (array) ($s['skips'] ?? [])))) as $d) {
                TaskSeriesSkip::query()->create(['series_id' => $new->id, 'date' => $d]);
            }
            $map[(string) ($s['id'] ?? '')] = $new->id;
        }

        return $map;
    }
}
