<?php

namespace App\Policies;

use App\Models\TaskReminder;
use App\Models\User;

/** スヌーズの予約を扱えるのは、その予定の持ち主だけ（routes/web.php の can:own,reminder） */
class TaskReminderPolicy
{
    public function own(User $user, TaskReminder $reminder): bool
    {
        return $reminder->task?->user_id === $user->id;
    }
}
