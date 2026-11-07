<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * 最初のユーザー（管理者自身）を作る。2人目からは招待リンクで、本人が登録する（php artisan invite:create）
 *
 * パスワードはコードや .env に書かず、このコマンドの中で入力する（画面には表示されない）
 *   docker compose exec app php artisan user:create
 * 自動で作る場合：--generate（一度だけ表示するので、すぐに控える）
 */
#[Signature('user:create {--name= : 表示名} {--email= : ログインに使うメールアドレス} {--generate : パスワードを自動で作って一度だけ表示する}')]
#[Description('最初のユーザーを作る（2人目からは invite:create で招待）')]
class CreateUser extends Command
{
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('ユーザーはすでに作られています。ほかの人は php artisan invite:create で招待してください（パスワードを変えるときは php artisan user:password）');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('表示名', 'いろどり手帳');
        $email = $this->option('email') ?: $this->ask('ログインに使うメールアドレス');

        $password = PasswordInput::read($this);
        if ($password === null) {
            return self::FAILURE;
        }

        $v = Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $m) {
                $this->error($m);
            }

            return self::FAILURE;
        }

        User::query()->create(['name' => $name, 'email' => Str::lower($email), 'password' => $password]);
        $this->info("ユーザーを作りました：{$email}");

        return self::SUCCESS;
    }
}
