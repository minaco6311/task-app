<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwnedParent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 「この予定だけ削除」した繰り返しの回（自動で作り直さないための印） */
#[Fillable(['series_id', 'date'])]
class TaskSeriesSkip extends Model
{
    use BelongsToOwnedParent;

    /** 持ち主は繰り返し（series）を通して決まる */
    public const OWNER_PARENT = 'series';

    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(TaskSeries::class, 'series_id');
    }
}
