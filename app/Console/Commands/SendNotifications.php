<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Support\Owner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 時刻になったLINE通知を送る（スケジューラーが毎分実行する）
 *
 * 手で確かめるとき：php artisan notifications:send --dry-run（記録も送信もしない）
 *
 * ユーザーごとに順番に処理する（Owner::run）。予定・通知履歴・LINE連携・送信数は、その人の分だけを使う
 * → 通知先や履歴がほかの人と混ざらない。1人で失敗しても、ほかの人の通知は続けて送る
 */
#[Signature('notifications:send {--dry-run : 送る予定だけを表示する（送信も記録もしない）}')]
#[Description('時刻になったLINE通知を送る')]
class SendNotifications extends Command
{
    private const ACTIONS = [
        'send' => '送る',
        'sent' => '送信済み',
        'simulated' => 'シミュレーション（LINEには送っていない）',
        'skip' => '見送り',
        'retry' => '送り直す',
        'retry_later' => '失敗（あとで送り直す）',
        'failed' => '失敗',
        'give_up' => '送り直しをやめた',
        'cancel' => '取り消し',
    ];

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $notConnected = 0;
        $failed = 0;

        User::query()->orderBy('id')->lazy()->each(function (User $user) use ($dispatcher, $dry, &$rows, &$notConnected, &$failed) {
            try {
                $res = Owner::run($user, fn () => $dispatcher->run(dryRun: $dry));
            } catch (\Throwable $e) {
                $failed++;
                report($e); // 例外の中身にトークンは入らない（送信の結果は SendResult の説明だけ）
                $this->error("ユーザー {$user->id} の通知の処理で問題が起きました（ほかの人の通知は続けます）");

                return;
            }
            if ($res['state'] === 'not_connected') {
                $notConnected++;

                return;
            }
            foreach ($res['results'] as $r) {
                $rows[] = [$user->id, $r['at'], $r['key'], self::ACTIONS[$r['action']] ?? $r['action'], $r['note'] ?? ''];
            }
        });

        if ($notConnected) {
            $this->warn("LINE_DRIVER=line ですが、LINEを連携していない {$notConnected} 人には送りませんでした");
        }
        if (! $rows) {
            $this->line(($dry ? '[確認のみ] ' : '').'いま送る通知はありません（送り方：'.config('line.driver').'）');
        } else {
            $this->table(['ユーザー', '送る時刻', '種類', '結果', 'メモ'], $rows);
        }
        if ($dry) {
            $this->line('[確認のみ] 送信も記録もしていません');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
