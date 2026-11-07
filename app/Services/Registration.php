<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Owner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 招待を1回だけ使って、アカウントを作る
 *
 * 同じ招待で同時に登録されても、アカウントは1つだけ：
 *   「未使用・無効化されていない・期限内」の条件つきで used_at を書き込み（UPDATE … WHERE used_at IS NULL …）、
 *   書き込めた1件の処理だけがユーザーを作る。ほかは0件になるので何も作らない
 *   （MySQL は同じ行の UPDATE を順番に処理し、後の UPDATE は先の結果を見て条件を判断する。SQLite は書き込みを1つずつ行う）
 * ユーザーを作れなかった（同じメールアドレスがすでにある）ときは、まとめて取り消す（招待は未使用のまま）
 */
class Registration
{
    public const EMAIL_TAKEN = 'email_taken';

    public const UNAVAILABLE = 'unavailable';

    /** @return User|self::EMAIL_TAKEN|self::UNAVAILABLE */
    public function register(Invitation $invitation, string $name, string $email, string $password): User|string
    {
        if (User::query()->where('email', $email)->exists()) {
            return self::EMAIL_TAKEN;
        }

        try {
            return DB::transaction(function () use ($invitation, $name, $email, $password) {
                $now = now();
                $claimed = Invitation::query()->whereKey($invitation->id)
                    ->where('token_hash', $invitation->token_hash)
                    ->where('email', $email)
                    ->usable($now->toImmutable())
                    ->update(['used_at' => $now, 'updated_at' => $now]);
                if ($claimed !== 1) {
                    return self::UNAVAILABLE;
                }

                $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
                Invitation::query()->whereKey($invitation->id)->update(['used_by' => $user->id]);
                // 新しい人は、ほかの人のデータを持たない状態から始める（凡例は初期の6つ）
                Owner::run($user, fn () => Category::createDefaults());

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return self::EMAIL_TAKEN; // ほぼ同時に同じメールアドレスで登録された（招待は使われずに残る）
        }
    }
}
