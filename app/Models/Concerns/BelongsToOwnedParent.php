<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * 親のデータを通して持ち主が決まるデータ（チェック項目・スヌーズの予約 → 予定、削除した回の印 → 繰り返し）
 * user_id を重ねて持たず、「親がいまのユーザーのもの」だけを読めるようにする
 * 使うモデルは OWNER_PARENT に親のリレーション名を書く
 */
trait BelongsToOwnedParent
{
    public static function bootBelongsToOwnedParent(): void
    {
        // 親（予定・繰り返し）は OwnerScope で、いまのユーザーのものだけになる
        static::addGlobalScope('owned-parent', fn (Builder $q) => $q->whereHas(static::OWNER_PARENT));

        // ほかの人の予定・繰り返しにぶら下げて作れないようにする
        static::saving(function (Model $model) {
            if (! $model->{static::OWNER_PARENT}()->exists()) {
                throw new LogicException('親のデータが見つかりません（ほかのユーザーのデータには追加できません）');
            }
        });
    }
}
