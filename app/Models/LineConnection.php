<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * LINE連携の情報（ユーザーごとに1行）
 * ・user_id … このアプリのユーザー（持ち主）
 * ・line_user_id … 通知を届ける LINE のユーザーID（U で始まる33文字）
 * トークンと LINE のユーザーIDは暗号化して保存し、画面・ログ・JSONには出さない
 */
#[Fillable(['channel_access_token', 'line_user_id', 'status', 'last_error', 'verified_at'])]
#[Hidden(['channel_access_token', 'line_user_id'])]
class LineConnection extends Model
{
    use BelongsToOwner;

    public const USER_ID_PATTERN = '/^U[0-9a-f]{32}$/';

    protected function casts(): array
    {
        return [
            'channel_access_token' => 'encrypted',
            'line_user_id' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    /** いまのユーザーの連携（なければ null） */
    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }

    /** 画面に出す形（末尾4文字だけ） */
    public function maskedToken(): string
    {
        return '••••••••'.mb_substr((string) $this->channel_access_token, -4);
    }

    public function maskedUserId(): string
    {
        $id = (string) $this->line_user_id;

        return mb_substr($id, 0, 3).'••••'.mb_substr($id, -4);
    }

    public function markOk(): void
    {
        $this->forceFill(['status' => 'ok', 'last_error' => null, 'verified_at' => now()])->save();
    }

    public function markError(string $message): void
    {
        $this->forceFill(['status' => 'error', 'last_error' => mb_substr($message, 0, 500)])->save();
    }
}
