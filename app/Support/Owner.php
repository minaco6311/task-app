<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * いま「だれのデータ」を扱っているか（予定・カテゴリ・設定・通知履歴・LINE連携の持ち主）
 *
 * ・画面（HTTP）：ログイン中のユーザー
 * ・スケジューラー・管理用コマンド：Owner::run($user, fn () => …) で1人ずつ決めて動かす
 * ・どちらでもないときに持ち主のあるデータを読み書きしようとしたら、例外で止める
 *   （「持ち主が決まっていない＝全員のデータが見える」状態を作らない）
 */
final class Owner
{
    /** Owner::run() で決めた持ち主（null = ログイン中のユーザーを使う） */
    private static ?int $forced = null;

    /** 持ち主のユーザーID（決まっていなければ例外） */
    public static function id(): int
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        $id = Auth::id();
        if ($id === null) {
            throw new LogicException('データの持ち主（ユーザー）が決まっていません。ログインするか Owner::run() の中で実行してください');
        }

        return (int) $id;
    }

    /** 持ち主が決まっているか */
    public static function check(): bool
    {
        return self::$forced !== null || Auth::id() !== null;
    }

    /**
     * 持ち主を決めて $fn を実行する（終わったら元に戻す。例外のときも戻す）
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function run(User|int $user, Closure $fn): mixed
    {
        $id = $user instanceof User ? (int) $user->getKey() : $user;
        if ($id <= 0) {
            throw new LogicException('持ち主のユーザーIDが正しくありません');
        }
        $previous = self::$forced;
        self::$forced = $id;
        try {
            return $fn();
        } finally {
            self::$forced = $previous;
        }
    }

    /** テスト用：前のテストの状態を残さない */
    public static function reset(): void
    {
        self::$forced = null;
    }
}
