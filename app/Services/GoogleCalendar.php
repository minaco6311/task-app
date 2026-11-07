<?php

namespace App\Services;

use App\Models\Task;
use App\Support\Jp;
use Carbon\CarbonImmutable;

/**
 * Googleカレンダー連携
 * ・登録画面を開くURL（API・ログイン不要。開いた画面で「保存」を押すと登録される）
 * ・.ics ファイル（PCのGoogleカレンダーでまとめて取り込める）
 */
class GoogleCalendar
{
    public static function url(Task $t): string
    {
        $ymd = fn (CarbonImmutable $d) => $d->format('Ymd');
        if ($t->all_day || ! $t->start) {
            $dates = $ymd($t->date).'/'.$ymd($t->date->addDay());
        } else {
            $dates = $ymd($t->date).'T'.str_replace(':', '', $t->start).'00/'
                .$ymd($t->date).'T'.str_replace(':', '', self::endOf($t)).'00';
        }

        $p = ['action' => 'TEMPLATE', 'text' => $t->title, 'dates' => $dates, 'ctz' => 'Asia/Tokyo'];
        if ($t->memo) {
            $p['details'] = $t->memo;
        }

        return 'https://calendar.google.com/calendar/render?'.http_build_query($p, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param iterable<Task> $tasks */
    public static function ics(iterable $tasks): string
    {
        $e = fn (string $s) => preg_replace('/([,;])/', '\\\\$1', str_replace(["\\", "\r\n", "\n"], ['\\\\', '\\n', '\\n'], $s));
        $stamp = gmdate('Ymd\THis\Z');
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'irodori-techo';

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//irodori-techo//JA', 'CALSCALE:GREGORIAN'];
        foreach ($tasks as $t) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:task-{$t->id}@{$host}";
            $lines[] = "DTSTAMP:{$stamp}";
            if ($t->all_day || ! $t->start) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$t->date->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$t->date->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART;TZID=Asia/Tokyo:'.$t->date->format('Ymd').'T'.str_replace(':', '', $t->start).'00';
                $lines[] = 'DTEND;TZID=Asia/Tokyo:'.$t->date->format('Ymd').'T'.str_replace(':', '', self::endOf($t)).'00';
            }
            $lines[] = 'SUMMARY:'.$e($t->title);
            if ($t->memo) {
                $lines[] = 'DESCRIPTION:'.$e($t->memo);
            }
            if ($t->category) {
                $lines[] = 'CATEGORIES:'.$e($t->category->name);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private static function endOf(Task $t): string
    {
        $s = Jp::toMin($t->start);
        $e = Jp::toMin($t->end);

        return $e !== null && $e > $s ? $t->end : Jp::fromMin($s + 60);
    }
}
