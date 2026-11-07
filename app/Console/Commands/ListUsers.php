<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ユーザーの一覧（ID・表示名・メールアドレス・登録日）。予定などの中身は表示しない
 *   docker compose exec app php artisan user:list
 */
#[Signature('user:list')]
#[Description('ユーザーの一覧を表示する')]
class ListUsers extends Command
{
    public function handle(): int
    {
        $users = User::query()->orderBy('id')->get(['id', 'name', 'email', 'created_at']);
        if ($users->isEmpty()) {
            $this->line('ユーザーはまだいません');

            return self::SUCCESS;
        }
        $this->table(['ID', '表示名', 'メールアドレス', '登録'], $users->map(fn (User $u) => [
            $u->id, $u->name, $u->email, $u->created_at?->format('Y-m-d H:i'),
        ])->all());

        return self::SUCCESS;
    }
}
