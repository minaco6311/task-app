<?php

namespace App\Console\Commands;

use App\Support\PasswordInput;
use App\Support\UserTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * ログインのメールアドレス・パスワードを変える（プレゼンの前・アプリを引き渡すときなど）
 *   docker compose exec app php artisan user:credentials --email=新しいアドレス --password
 *
 * ・指定した1人のユーザーを書き換えるだけ（ユーザーは増えない・消えない。ID・表示名・予定・カテゴリ・設定はそのまま）
 * ・ユーザーが2人以上いるときは --user=いまのメールアドレス（または ID）が必須。1人だけなら省略できる
 * ・パスワードはこのコマンドの中で2回入力する（画面に表示しない）。引数・環境変数・ログには残さない
 * ・変えたら、ほかの端末のログインも切る
 */
#[Signature('user:credentials
    {--user= : 変える人のいまのメールアドレスまたは ID（ユーザーが2人以上のときは必須）}
    {--email= : 新しいメールアドレス（書かなければ、いまのまま）}
    {--password : パスワードも変える（このあと画面に表示せずに2回入力する）}')]
#[Description('ログインのメールアドレス・パスワードを変える')]
class UpdateUserCredentials extends Command
{
    public function handle(): int
    {
        // 変える人を必ず1人に決める（2人以上いて --user がなければ、何もせずに止まる）
        $user = UserTarget::resolve($this, $this->option('user'));
        if (! $user) {
            return self::FAILURE;
        }

        $email = $this->option('email');
        $changePassword = (bool) $this->option('password');
        // 何も指定しなかったときは、画面で聞く
        if ($email === null && ! $changePassword && $this->input->isInteractive()) {
            $email = $this->ask('新しいメールアドレス（変えないときは空のまま Enter）') ?: null;
            $changePassword = $this->confirm('パスワードも変えますか？', true);
        }
        if ($email === null && ! $changePassword) {
            $this->error('変える内容がありません。--email=新しいアドレス か --password を付けてください');

            return self::FAILURE;
        }

        if ($email !== null) {
            $email = Str::lower(trim((string) $email));
            $v = Validator::make(['email' => $email], [
                'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            ], [], ['email' => 'メールアドレス']);
            if ($v->fails()) {
                foreach ($v->errors()->all() as $m) {
                    $this->error($m);
                }

                return self::FAILURE;
            }
        }

        $password = null;
        if ($changePassword) {
            $password = PasswordInput::ask($this);
            if ($password === null) {
                return self::FAILURE;
            }
        }

        $this->line("変更の内容（ID {$user->id} の人）：");
        $this->line('  メールアドレス：'.($email === null || $email === $user->email ? "変えない（{$user->email}）" : "{$user->email} → {$email}"));
        $this->line('  パスワード：'.($password === null ? '変えない' : '変える'));
        $this->line("  表示名：変えない（{$user->name}）");
        if (! $this->confirm('この内容で変更しますか？', false)) {
            $this->warn('何も変えずに終わりました');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $email, $password) {
            if ($email !== null) {
                $user->email = $email;
            }
            if ($password !== null) {
                $user->password = $password; // User の casts（hashed）でハッシュにして保存する
            }
            $user->remember_token = null;
            $user->save();

            // ほかの端末のログインも切る
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });

        $this->info("変更しました。次からは {$user->email} でログインしてください（ほかの端末のログインは切れました）");

        return self::SUCCESS;
    }
}
