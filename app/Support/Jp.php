<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** 日付・時刻まわりの小さな道具（日本語の表示） */
class Jp
{
    public const WEEK = ['日', '月', '火', '水', '木', '金', '土'];

    public const BEFORE_OPTIONS = [
        0 => '通知しない', 5 => '5分前', 10 => '10分前', 15 => '15分前', 30 => '30分前',
        60 => '1時間前', 120 => '2時間前', 180 => '3時間前',
    ];

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }

    /** 「YYYY-MM-DD」を日付に。読めなければ今日 */
    public static function parseDate(?string $s): CarbonImmutable
    {
        if ($s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $s);
            if ($d && $d->format('Y-m-d') === $s) {
                return $d;
            }
        }

        return self::today();
    }

    /** 10月27日(火) */
    public static function dateLabel(DateTimeInterface $d): string
    {
        $c = CarbonImmutable::instance($d);

        return "{$c->month}月{$c->day}日(".self::WEEK[$c->dayOfWeek].')';
    }

    /** 10/27(火) 14:00 */
    public static function dateTimeLabel(DateTimeInterface $d): string
    {
        $c = CarbonImmutable::instance($d);

        return "{$c->month}/{$c->day}(".self::WEEK[$c->dayOfWeek].') '.$c->format('H:i');
    }

    public static function beforeLabel(int $min): string
    {
        return self::BEFORE_OPTIONS[$min] ?? "{$min}分前";
    }

    public static function afterLabel(int $min): string
    {
        return $min >= 60 && $min % 60 === 0 ? ($min / 60).'時間後' : "{$min}分後";
    }

    public static function toMin(?string $hm): ?int
    {
        if (! $hm || ! preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** 分 → 「HH:MM」。0:00〜23:59 に収める */
    public static function fromMin(int $m): string
    {
        $m = max(0, min($m, 23 * 60 + 59));

        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    public static function at(DateTimeInterface $date, string $hm): CarbonImmutable
    {
        $min = self::toMin($hm) ?? 0;

        return CarbonImmutable::instance($date)->startOfDay()->addMinutes($min);
    }
}
