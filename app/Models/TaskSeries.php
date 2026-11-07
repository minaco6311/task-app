<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Support\Jp;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 繰り返し予定のルールと、各回の元の内容
 * ・毎日 / 毎週（曜日を複数） / 毎月（基準日。ない月は末日） / 平日（月〜金）
 * ・終わりなし / 指定した日まで
 * ・ユーザーごと（削除した回の印は繰り返しを通して持ち主が決まる）
 */
#[Fillable([
    'freq', 'weekdays', 'month_day', 'starts_on', 'until', 'generated_until',
    'title', 'all_day', 'start_time', 'end_time', 'category_id', 'memo', 'priority',
    'notify_day_before', 'notify_morning', 'notify_before_minutes', 'remind_until_done', 'subtasks',
])]
class TaskSeries extends Model
{
    use BelongsToOwner;

    protected $table = 'task_series';

    public const FREQS = ['daily' => '毎日', 'weekly' => '毎週', 'weekdays' => '平日（月〜金）', 'monthly' => '毎月'];

    /** 各回に写す項目 */
    public const TEMPLATE = [
        'title', 'all_day', 'start_time', 'end_time', 'category_id', 'memo', 'priority',
        'notify_day_before', 'notify_morning', 'notify_before_minutes', 'remind_until_done',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'until' => 'immutable_date',
            'generated_until' => 'immutable_date',
            'all_day' => 'boolean',
            'priority' => 'integer',
            'month_day' => 'integer',
            'notify_day_before' => 'boolean',
            'notify_morning' => 'boolean',
            'notify_before_minutes' => 'integer',
            'remind_until_done' => 'boolean',
            'subtasks' => 'array',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'series_id');
    }

    public function skips(): HasMany
    {
        return $this->hasMany(TaskSeriesSkip::class, 'series_id');
    }

    /** @return list<int> 0=日 … 6=土 */
    public function weekdayList(): array
    {
        return array_values(array_map('intval', array_filter(explode(',', (string) $this->weekdays), 'strlen')));
    }

    /** 「毎週 月・水・金」「毎月31日（ない月は末日）」など */
    public function label(): string
    {
        $base = match ($this->freq) {
            'weekly' => '毎週 '.implode('・', array_map(fn ($d) => Jp::WEEK[$d], $this->weekdayList())),
            'monthly' => "毎月{$this->month_day}日".($this->month_day > 28 ? '（ない月は末日）' : ''),
            default => self::FREQS[$this->freq] ?? $this->freq,
        };

        return $base.($this->until ? '・'.Jp::dateLabel($this->until).'まで' : '');
    }
}
