<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Recurrence;
use App\Support\Owner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 繰り返し予定を、今日から90日先まで補充する（スケジューラーが毎日実行する）
 * サーバーが止まっていた日があっても、次に動いたときに足りない回を作る。同じ回は二重に作らない
 * ユーザーごとに順番に処理する（Owner::run）。作った回は、繰り返しの持ち主の予定になる
 */
#[Signature('tasks:fill-recurring')]
#[Description('繰り返し予定を90日先まで補充する')]
class FillRecurringTasks extends Command
{
    public function handle(): int
    {
        $n = 0;
        $failed = 0;
        User::query()->orderBy('id')->lazy()->each(function (User $user) use (&$n, &$failed) {
            try {
                $n += Owner::run($user, fn () => Recurrence::fillAll());
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->error("ユーザー {$user->id} の繰り返し予定の補充で問題が起きました（ほかの人の分は続けます）");
            }
        });
        $this->info("繰り返し予定を {$n}件 作りました（".Recurrence::HORIZON_DAYS.'日先まで）');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
