<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Scopes\OwnerScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** 通知の履歴（送った・シミュレーション・失敗・見送り・取り消し）。ユーザーごと（同じ dedupe_key でもユーザーが違えば別の通知） */
#[Fillable([
    'dedupe_key', 'type', 'task_id', 'scheduled_at', 'status', 'attempts', 'retry_key',
    'signature', 'message', 'next_retry_at', 'last_error', 'sent_at',
])]
class NotificationLog extends Model
{
    use BelongsToOwner, MassPrunable;

    public const TYPE_LABELS = [
        'day_before' => '前日',
        'morning' => '当日朝',
        'before' => '開始前',
        'snooze' => '再通知',
        'nag' => '完了まで再通知',
        'test' => 'テスト送信',
    ];

    public const STATUS_LABELS = [
        'pending' => '送信中',
        'sent' => '送信済み',
        'simulated' => 'シミュレーション',
        'failed' => '失敗',
        'skipped' => '見送り',
        'canceled' => '取り消し',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /** 古い履歴を消す（php artisan model:prune）。古さだけで決めるので、全員分をまとめて消す */
    public function prunable(): Builder
    {
        return static::withoutGlobalScope(OwnerScope::class)->where('created_at', '<', now()->subDays(config('line.keep_logs_days')));
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        if ($this->status === 'failed' && $this->next_retry_at) {
            return '失敗（送り直し予定）';
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
