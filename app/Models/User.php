<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * ログインするユーザー（招待された人が、招待リンクから自分で作る）
 * 予定・カテゴリ・設定・通知履歴・LINE連携は、それぞれ user_id で持ち主を決める（App\Models\Concerns\BelongsToOwner）
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** パスワードの長さ（bcrypt は 72 バイトより後ろを使わないので、それより長いものは受け付けない） */
    public const PASSWORD_MIN = 12;

    public const PASSWORD_MAX_BYTES = 72;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** パスワードの入力チェック（画面の登録・変更で共通） */
    public static function passwordRules(): array
    {
        return [
            'required', 'string', 'min:'.self::PASSWORD_MIN, 'confirmed',
            function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && strlen($value) > self::PASSWORD_MAX_BYTES) {
                    $fail('パスワードが長すぎます（半角で'.self::PASSWORD_MAX_BYTES.'文字まで）');
                }
            },
        ];
    }
}
