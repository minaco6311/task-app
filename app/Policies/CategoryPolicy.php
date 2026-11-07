<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/** カテゴリを扱えるのは持ち主だけ（routes/web.php の can:own,category） */
class CategoryPolicy
{
    public function own(User $user, Category $category): bool
    {
        return $category->user_id === $user->id;
    }
}
