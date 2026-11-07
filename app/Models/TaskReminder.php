<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwnedParent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * スヌーズ（あとで再通知）の予約
 * 送ったかどうかは通知履歴（dedupe_key = snooze:{id}）で二重送信を防ぐ
 */
#[Fillable(['task_id', 'remind_at'])]
class TaskReminder extends Model
{
    use BelongsToOwnedParent;

    /** 持ち主は予定（task）を通して決まる */
    public const OWNER_PARENT = 'task';

    protected function casts(): array
    {
        return ['remind_at' => 'immutable_datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
