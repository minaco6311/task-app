<?php

namespace App\Models\Scopes;

use App\Support\Owner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** 持ち主（user_id）が、いまのユーザーのものだけにする。持ち主が決まっていなければ例外（全員分を返さない） */
class OwnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('user_id'), Owner::id());
    }
}
