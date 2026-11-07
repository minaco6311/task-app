<?php

namespace App\Policies;

use App\Models\Subtask;
use App\Models\User;

/** チェック項目を扱えるのは、その予定の持ち主だけ（routes/web.php の can:own,subtask） */
class SubtaskPolicy
{
    public function own(User $user, Subtask $subtask): bool
    {
        return $subtask->task?->user_id === $user->id;
    }
}
