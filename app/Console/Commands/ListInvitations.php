<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 招待の一覧（未使用・利用済み・期限切れ・無効化）。招待リンク（トークン）は保存していないので表示されない
 *   docker compose exec app php artisan invite:list
 */
#[Signature('invite:list {--all : 利用済み・期限切れ・無効化も含めてすべて表示する（標準は直近30日の分）}')]
#[Description('招待の一覧を表示する')]
class ListInvitations extends Command
{
    public function handle(): int
    {
        $list = Invitation::query()->with('usedBy')
            ->when(! $this->option('all'), fn ($q) => $q->where('created_at', '>=', now()->subDays(30)))
            ->orderByDesc('id')->get();
        if ($list->isEmpty()) {
            $this->line('招待はありません');

            return self::SUCCESS;
        }

        $this->table(['ID', 'メールアドレス', '状態', '発行', '期限', '利用・無効化'], $list->map(fn (Invitation $i) => [
            $i->id,
            $i->email,
            $i->stateLabel(),
            $i->created_at?->format('Y-m-d H:i'),
            $i->expires_at->format('Y-m-d H:i'),
            $i->used_at ? $i->used_at->format('Y-m-d H:i').'（ユーザー '.($i->used_by ?? '削除済み').'）' : ($i->revoked_at?->format('Y-m-d H:i') ?? ''),
        ])->all());

        return self::SUCCESS;
    }
}
