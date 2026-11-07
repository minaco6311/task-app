<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * 予定を扱えるのは持ち主だけ（routes/web.php の can:own,task）
 * OwnerScope でほかの人の予定はそもそも見つからない（404）が、念のためここでも確かめる
 */
class TaskPolicy
{
    public function own(User $user, Task $task): bool
    {
        return $task->user_id === $user->id;
    }
}
