<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 招待（管理者が php artisan invite:create で発行する。1回だけ・7日間）
 *
 * ・トークンそのものは保存しない。SHA-256 のハッシュだけを保存する（DB が漏れても招待リンクは作れない）
 * ・状態：未使用（usable）/ 利用済み（used_at）/ 無効化（revoked_at）/ 期限切れ（expires_at を過ぎた）
 * ・画面から作ったり変えたりはできない（$fillable なし。管理用コマンドと登録の処理だけが forceFill で書く）
 */
class Invitation extends Model
{
    public const VALID_DAYS = 7;

    /** トークンの形（64文字の16進数 = 256ビットの乱数） */
    public const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** 新しいトークン（暗号学的に安全な乱数） */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** トークンから探す（形が違うものは DB に問い合わせずに null） */
    public static function findByToken(string $token): ?self
    {
        if (! preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        return static::query()->where('token_hash', self::hashToken($token))->first();
    }

    /** まだ使える招待（未使用・無効化されていない・期限内） */
    public function scopeUsable(Builder $q, ?CarbonImmutable $now = null): Builder
    {
        return $q->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', $now ?? now());
    }

    /** usable / used / revoked / expired */
    public function state(?CarbonImmutable $now = null): string
    {
        return match (true) {
            $this->used_at !== null => 'used',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->lte($now ?? now()) => 'expired',
            default => 'usable',
        };
    }

    public function stateLabel(): string
    {
        return ['usable' => '未使用', 'used' => '利用済み', 'revoked' => '無効化', 'expired' => '期限切れ'][$this->state()];
    }

    /** 画面に出す招待先（一部を伏せる。例：fr•••@example.com） */
    public function maskedEmail(): string
    {
        [$local, $domain] = array_pad(explode('@', $this->email, 2), 2, '');

        return mb_substr($local, 0, 2).'•••@'.$domain;
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
