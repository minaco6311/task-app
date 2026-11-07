<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * 招待リンクを発行する（管理者だけ。サーバーで実行する）
 *   docker compose exec app php artisan invite:create --email=相手のメールアドレス
 *
 * ・リンクは1回だけ使えて、7日間で期限が切れる。登録できるのは、ここで指定したメールアドレスだけ
 * ・リンクはこの画面に一度だけ表示する（DB にはハッシュしか残らないので、あとから表示し直せない）
 *   → 相手に直接渡す。チャットなどに貼った場合は、相手が登録したら（利用済みになったら）もう使えない
 */
#[Signature('invite:create {--email= : 招待する人のメールアドレス}')]
#[Description('招待リンクを発行する（1回だけ・7日間）')]
class CreateInvitation extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) ($this->option('email') ?: $this->ask('招待する人のメールアドレス'))));
        $v = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], [], ['email' => 'メールアドレス']);
        if ($v->fails()) {
            $this->error($v->errors()->first());

            return self::FAILURE;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->error('このメールアドレスのユーザーはすでにいます（招待は不要です）');

            return self::FAILURE;
        }
        if (Invitation::query()->where('email', $email)->usable()->exists()) {
            $this->error('このメールアドレスには、まだ使える招待があります。作り直すときは php artisan invite:list で ID を確かめ、php artisan invite:revoke ID で無効にしてから発行してください');

            return self::FAILURE;
        }

        $token = Invitation::newToken();
        $invitation = new Invitation;
        $invitation->forceFill([
            'email' => $email,
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ])->save();

        $this->info("招待を発行しました（ID {$invitation->id}・{$email}・".$invitation->expires_at->format('Y-m-d H:i').' まで・1回だけ）');
        $this->warn('次の招待リンクを相手に直接渡してください。この画面にだけ表示します（あとから表示し直せません）：');
        $this->line(rtrim(config('app.url'), '/').'/invite#'.$token);

        return self::SUCCESS;
    }
}
