<?php

namespace App\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** パスワードを入力してもらう（または自動で作る）。どこにも記録しない */
final class PasswordInput
{
    public const MIN_LENGTH = 12;

    public static function read(Command $cmd): ?string
    {
        if ($cmd->hasOption('generate') && $cmd->option('generate')) {
            $password = Str::password(20, symbols: false);
            $cmd->warn('パスワード（この画面にだけ表示します。すぐに控えてください）：');
            $cmd->line($password);

            return $password;
        }

        return self::ask($cmd);
    }

    /** 画面に表示せずに2回入力してもらう（自動で作る機能はない） */
    public static function ask(Command $cmd): ?string
    {
        $password = (string) $cmd->secret('パスワード（'.self::MIN_LENGTH.'文字以上。入力しても表示されません）');
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $cmd->error('パスワードは'.self::MIN_LENGTH.'文字以上にしてください');

            return null;
        }
        if ($password !== (string) $cmd->secret('確認のため、もう一度入力してください')) {
            $cmd->error('パスワードが一致しませんでした');

            return null;
        }

        return $password;
    }
}
