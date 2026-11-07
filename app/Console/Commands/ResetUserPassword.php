<?php

namespace App\Console\Commands;

use App\Support\PasswordInput;
use App\Support\UserTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ログインのパスワードを変える（本人が忘れたときに、管理者がサーバーで行う）
 *   docker compose exec app php artisan user:password --user=その人のメールアドレス
 *
 * ・ユーザーが2人以上いるときは --user が必須（メールアドレスか ID。php artisan user:list で確かめる）。1人だけなら省略できる
 * ・だれのパスワードを変えるかを表示して、確認してから変える
 * ・新しいパスワードは画面に表示せずに2回入力する（--generate なら自動で作って一度だけ表示）。本人に直接伝え、本人は設定画面で変え直す
 * ・変えたら、その人のほかの端末のログインも切る
 */
#[Signature('user:password
    {--user= : 変える人のメールアドレスまたは ID（ユーザーが2人以上のときは必須）}
    {--generate : パスワードを自動で作って一度だけ表示する}')]
#[Description('ログインのパスワードを変える（管理者用）')]
class ResetUserPassword extends Command
{
    public function handle(): int
    {
        $user = UserTarget::resolve($this, $this->option('user'));
        if (! $user) {
            return self::FAILURE;
        }

        $this->line("パスワードを変える人：ID {$user->id}・{$user->name}・{$user->email}");
        if (! $this->confirm('この人のパスワードを変えますか？', false)) {
            $this->warn('何も変えずに終わりました');

            return self::FAILURE;
        }

        $password = PasswordInput::read($this);
        if ($password === null) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();
            // ほかの端末のログインも切る（この人の分だけ）
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });
        $this->info("パスワードを変えました：{$user->email}");

        return self::SUCCESS;
    }
}
