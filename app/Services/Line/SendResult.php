<?php

namespace App\Services\Line;

/** 送信の結果。error には利用者向けの説明だけを入れる（トークンなどは入れない） */
final class SendResult
{
    private function __construct(
        public readonly string $status,        // sent / simulated / failed
        public readonly bool $retryable = false,
        public readonly ?string $error = null,
        public readonly bool $authError = false, // トークンが正しくない → 連携の再設定が必要
    ) {}

    public static function sent(): self
    {
        return new self('sent');
    }

    public static function simulated(): self
    {
        return new self('simulated');
    }

    public static function retry(string $error): self
    {
        return new self('failed', true, $error);
    }

    public static function fail(string $error, bool $authError = false): self
    {
        return new self('failed', false, $error, $authError);
    }

    public function ok(): bool
    {
        return $this->status !== 'failed';
    }
}
