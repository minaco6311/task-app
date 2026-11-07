<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 管理用コマンドで「どのユーザーを変えるか」を必ず1人に決める
 * ・--user=メールアドレス または --user=ID（php artisan user:list で確かめる）。メールアドレスは完全一致（大文字・小文字は区別しない）
 * ・--user を書かないときは、ユーザーがちょうど1人のときだけその人（2人以上なら止まる。勝手に1人目を選ばない）
 */
final class UserTarget
{
    public static function resolve(Command $cmd, ?string $user): ?User
    {
        $user = $user === null ? null : trim($user);
        if ($user !== null && $user !== '') {
            $found = ctype_digit($user)
                ? User::query()->whereKey((int) $user)->get()
                : User::query()->where('email', Str::lower($user))->get();
            if ($found->count() !== 1) {
                $cmd->error("「{$user}」に当てはまるユーザーがいません（php artisan user:list で確かめてください）");

                return null;
            }

            return $found->first();
        }

        $count = User::query()->count();
        if ($count === 1) {
            return User::query()->firstOrFail();
        }
        $cmd->error($count === 0
            ? 'ユーザーがまだいません。最初のユーザーは php artisan user:create で作ります'
            : "ユーザーが {$count} 人います。--user=メールアドレス（または ID）で、変える人を指定してください（php artisan user:list で確かめられます）");

        return null;
    }
}
