<?php

namespace App\Services\Line;

use App\Support\Owner;
use Illuminate\Support\Facades\Log;

/**
 * シミュレーション：LINEには送らない（LINE_DRIVER=log）
 * 本文は通知履歴（画面）にだけ残し、サーバーのログには書かない（ほかの人の予定の内容をログに残さないため）
 */
class LogMessenger implements Messenger
{
    public function send(string $text, string $retryKey): SendResult
    {
        Log::info('[LINE シミュレーション] LINEには送っていません', ['user_id' => Owner::id(), 'chars' => mb_strlen($text), 'retry_key' => $retryKey]);

        return SendResult::simulated();
    }

    public function isLive(): bool
    {
        return false;
    }

    /** シミュレーションでは LINE に問い合わせない */
    public function quota(): ?array
    {
        return null;
    }
}
