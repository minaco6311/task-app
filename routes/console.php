<?php

use App\Models\NotificationLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ----- 定期実行（Laravel Scheduler） -----
// Docker の scheduler コンテナが php artisan schedule:work で動かす（サーバーでも同じ）

// 時刻になったLINE通知を送る（毎分）。前の実行が終わっていなければ重ねて動かさない
Schedule::command('notifications:send')
    ->everyMinute()
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping(10);

// 繰り返し予定を90日先まで補充する（毎日 0:10。サーバーが止まっていても、次に動いたときに補充する）
Schedule::command('tasks:fill-recurring')
    ->dailyAt('00:10')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping(30);

// 古い通知履歴を消す（毎日 4:00）
Schedule::command('model:prune', ['--model' => [NotificationLog::class]])
    ->dailyAt('04:00')
    ->timezone('Asia/Tokyo');
