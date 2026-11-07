<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwnedParent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * サブタスク（予定のチェック項目）
 * サブタスクを完了にしても、予定（親）は完了にならず、LINE通知も止まらない
 */
#[Fillable(['title', 'done', 'sort_order'])]
class Subtask extends Model
{
    use BelongsToOwnedParent;

    /** 持ち主は予定（task）を通して決まる */
    public const OWNER_PARENT = 'task';

    public const MAX_PER_TASK = 20;

    protected function casts(): array
    {
        return [
            'done' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
