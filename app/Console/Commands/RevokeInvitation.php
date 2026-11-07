<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * まだ使われていない招待を無効にする（リンクを渡し間違えた・送り先を変えるときなど）
 *   docker compose exec app php artisan invite:revoke ID（ID は invite:list で確かめる）
 * 利用済みの招待は無効にできない（登録済みのアカウントには影響しない）
 */
#[Signature('invite:revoke {id : 招待の ID（invite:list で確かめる）} {--force : 確認をしない}')]
#[Description('未使用の招待を無効にする')]
class RevokeInvitation extends Command
{
    public function handle(): int
    {
        $invitation = Invitation::query()->find((int) $this->argument('id'));
        if (! $invitation) {
            $this->error('その ID の招待はありません（php artisan invite:list で確かめてください）');

            return self::FAILURE;
        }
        $state = $invitation->state();
        if ($state === 'used' || $state === 'revoked') {
            $this->error("この招待は{$invitation->stateLabel()}のため、無効にする必要はありません");

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("招待 {$invitation->id}（{$invitation->email}・{$invitation->stateLabel()}）を無効にしますか？", false)) {
            $this->warn('何も変えずに終わりました');

            return self::FAILURE;
        }

        // 登録と同時に押されても、未使用のときだけ無効にする（使われた招待は変えない）
        $n = Invitation::query()->whereKey($invitation->id)->whereNull('used_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($n !== 1) {
            $this->error('この招待はたったいま使われたか、無効にされました。php artisan invite:list で確かめてください');

            return self::FAILURE;
        }
        $this->info("招待 {$invitation->id} を無効にしました。このリンクではもう登録できません");

        return self::SUCCESS;
    }
}
