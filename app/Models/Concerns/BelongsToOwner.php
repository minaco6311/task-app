<?php

namespace App\Models\Concerns;

use App\Models\Scopes\OwnerScope;
use App\Models\User;
use App\Support\Owner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 持ち主（user_id）のあるデータ（予定・カテゴリ・繰り返し・設定・通知履歴・LINE連携）
 *
 * ・読むとき：いまのユーザーのものだけ（OwnerScope）。ほかの人の ID を指定しても見つからない（404）
 * ・作るとき：user_id は自動でいまのユーザーにする。フォームから user_id を送っても使わない（$fillable に入れていない）
 * ・持ち主の付け替えはできない（例外で止める）
 */
trait BelongsToOwner
{
    public static function bootBelongsToOwner(): void
    {
        static::addGlobalScope(new OwnerScope);

        static::creating(function (Model $model) {
            $owner = Owner::id();
            if ($model->getAttribute('user_id') === null) {
                $model->setAttribute('user_id', $owner);
            } elseif ((int) $model->getAttribute('user_id') !== $owner) {
                throw new LogicException('ほかのユーザーのデータは作れません');
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('user_id')) {
                throw new LogicException('データの持ち主は変えられません');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function initializeBelongsToOwner(): void
    {
        $this->mergeCasts(['user_id' => 'integer']);
    }
}
