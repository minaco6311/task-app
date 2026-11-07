<?php

namespace App\Services\Line;

/**
 * LINEに1通送る
 * ・LINE_DRIVER=log  → LogMessenger（送らずにログへ記録）
 * ・LINE_DRIVER=line → MessagingApiMessenger（LINE Messaging API の push）
 */
interface Messenger
{
    /** @param string $retryKey X-Line-Retry-Key（送り直すときは最初と同じ値・同じ本文で呼ぶ） */
    public function send(string $text, string $retryKey): SendResult;

    /** 実際にLINEへ送るか（false ならシミュレーション） */
    public function isLive(): bool;

    /**
     * LINE側で数えた今月の送信数と上限（取れないときは null）
     *
     * @return array{used: int, limit: ?int}|null limit が null = 上限の設定なし
     */
    public function quota(): ?array;
}
