<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Support\Jp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'date', 'all_day', 'start_time', 'end_time', 'category_id', 'memo', 'done', 'priority',
    'notify_day_before', 'notify_morning', 'notify_before_minutes', 'remind_until_done', 'gcal_added',
    'series_id', 'series_date', 'series_modified',
])]
/** 予定。ユーザーごと（チェック項目・スヌーズの予約は予定を通して持ち主が決まる） */
class Task extends Model
{
    use BelongsToOwner;

    /** 優先度（小さいほど高い。並べ替えにそのまま使う） */
    public const PRIORITY_HIGH = 1;

    public const PRIORITY_NORMAL = 2;

    public const PRIORITY_LOW = 3;

    public const PRIORITIES = [self::PRIORITY_HIGH => '高', self::PRIORITY_NORMAL => '中', self::PRIORITY_LOW => '低'];

    /** 新しく作るときの初期値（DB の既定値と同じ） */
    protected $attributes = ['priority' => self::PRIORITY_NORMAL];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'all_day' => 'boolean',
            'done' => 'boolean',
            'priority' => 'integer',
            'notify_day_before' => 'boolean',
            'notify_morning' => 'boolean',
            'notify_before_minutes' => 'integer',
            'remind_until_done' => 'boolean',
            'gcal_added' => 'boolean',
            'series_id' => 'integer',
            'series_date' => 'immutable_date',
            'series_modified' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** サブタスク（並び順） */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('sort_order')->orderBy('id');
    }

    /** 繰り返し予定のルール（繰り返しでなければ null） */
    public function series(): BelongsTo
    {
        return $this->belongsTo(TaskSeries::class, 'series_id');
    }

    /** スヌーズの予約（時刻順） */
    public function reminders(): HasMany
    {
        return $this->hasMany(TaskReminder::class)->orderBy('remind_at');
    }

    /** 優先度の高い順（高 → 中 → 低） */
    public function scopeByPriority(Builder $q): Builder
    {
        return $q->orderBy('priority');
    }

    public function isHighPriority(): bool
    {
        return $this->priority === self::PRIORITY_HIGH;
    }

    /** 終日 → 時刻あり の順、時刻の早い順 */
    public function scopeInDayOrder(Builder $q): Builder
    {
        return $q->orderByDesc('all_day')->orderBy('start_time')->orderBy('id');
    }

    /** 「14:00」形式の開始時刻（終日なら空文字） */
    protected function start(): Attribute
    {
        return Attribute::get(fn () => $this->all_day || ! $this->start_time ? '' : substr($this->start_time, 0, 5));
    }

    protected function end(): Attribute
    {
        return Attribute::get(fn () => $this->all_day || ! $this->end_time ? '' : substr($this->end_time, 0, 5));
    }

    protected function color(): Attribute
    {
        return Attribute::get(fn () => $this->category?->color ?? '#7C8A94');
    }

    protected function timeLabel(): Attribute
    {
        return Attribute::get(fn () => $this->all_day ? '終日' : "{$this->start}–{$this->end}");
    }

    public function startsAt(): ?CarbonImmutable
    {
        return $this->start ? Jp::at($this->date, $this->start) : null;
    }

    /**
     * フォームの値を保存できる形にそろえる
     * ・終日なら時刻を消す
     * ・終了が空か開始より前なら、開始の1時間後にする
     */
    public static function normalizeTimes(array $data): array
    {
        if (! empty($data['all_day']) || empty($data['start_time'])) {
            $data['all_day'] = true;
            $data['start_time'] = null;
            $data['end_time'] = null;

            return $data;
        }

        $s = Jp::toMin($data['start_time']);
        $e = empty($data['end_time']) ? null : Jp::toMin($data['end_time']);
        if ($e === null || $e <= $s) {
            $data['end_time'] = Jp::fromMin($s + 60);
        }

        return $data;
    }
}
