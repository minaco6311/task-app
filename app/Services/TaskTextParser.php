<?php

namespace App\Services;

use App\Models\Category;
use App\Support\Jp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 手帳からコピーした文字 → 予定の候補
 * 対応例：「10/27(火)」「10月28日」「29日」「14:00」「19:00-20:00」「午後3時半」「10時〜11時30分」
 * 日付だけの行は、その下の行の日付として使う
 */
class TaskTextParser
{
    /** キーワード → 初期カテゴリの key */
    private const HINTS = [
        'health' => '/病院|歯医者|歯科|クリニック|通院|健診|検診|ジム|整体|薬/u',
        'work' => '/会議|打ち?合わ?せ|MTG|ミーティング|締め?切|提出|出張|面談|商談|仕事|資料/iu',
        'study' => '/勉強|試験|テスト|講座|セミナー|授業|英語|資格/u',
        'family' => '/子ども|子供|家族|母|父|保育|学校|参観|運動会|誕生日|実家/u',
        'errand' => '/役所|銀行|支払|振込|郵便|ゴミ|ごみ|手続|更新|申込|返却|買い物/u',
    ];

    /** @param Collection<int, Category> $categories */
    public function __construct(private Collection $categories) {}

    /**
     * @return list<array{title:string,date:string,start:string,end:string,category_id:int|null,on:bool}>
     */
    public function parse(string $text, CarbonImmutable $base): array
    {
        $curDate = null;
        $out = [];

        foreach (preg_split('/\r?\n/', $text) as $raw) {
            $line = trim($this->normalize($raw));
            if ($line === '') {
                continue;
            }

            ['date' => $date, 'start' => $start, 'end' => $end, 'title' => $title] = $this->parseLine($line, $base);
            if ($date) {
                $curDate = $date;
            }
            if ($title === '') {
                continue; // 「10/27(火)」だけの行は日付の見出しとして使う
            }

            if ($start && (! $end || Jp::toMin($end) <= Jp::toMin($start))) {
                $end = Jp::fromMin(Jp::toMin($start) + 60);
            }

            $out[] = [
                'title' => mb_substr($title, 0, 80),
                'date' => $date ?? $curDate ?? $base->format('Y-m-d'),
                'start' => $start,
                'end' => $end,
                'category_id' => $this->guessCategory($title),
                'on' => true,
            ];
        }

        return $out;
    }

    /**
     * 1行を読む（手帳からの取り込みとクイック登録で共通）。$line は normalize() 済みのもの
     * meridiem = 'am' / 'pm'（午前・午後の指定）、hourWord = 「○時」の形で書かれていた
     *
     * @return array{date: ?string, start: string, end: string, title: string, meridiem: ?string, hourWord: bool}
     */
    private function parseLine(string $line, CarbonImmutable $base): array
    {
        // 日付
        $date = null;
        if (preg_match('/(\d{1,2})\s*[\/月.]\s*(\d{1,2})\s*日?/u', $line, $m)
            && $m[1] >= 1 && $m[1] <= 12 && $m[2] >= 1 && $m[2] <= 31) {
            $date = $this->makeDate($base, (int) $m[1], (int) $m[2]);
            $line = $this->replaceOnce($m[0], ' ', $line);
        } elseif (preg_match('/^(\d{1,2})\s*日/u', $line, $m) && $m[1] >= 1 && $m[1] <= 31) {
            $date = $base->startOfMonth()->addDays((int) $m[1] - 1)->format('Y-m-d');
            $line = $this->replaceOnce($m[0], ' ', $line);
        }
        $line = preg_replace('/[(（]\s*[日月火水木金土祝]\s*[)）]/u', ' ', $line);

        // 時刻
        $start = '';
        $end = '';
        $pm = (bool) preg_match('/午後|PM/iu', $line);
        $meridiem = $pm ? 'pm' : (preg_match('/午前|AM/iu', $line) ? 'am' : null);
        $hourWord = false;
        if (preg_match('/(\d{1,2})\s*(?::|時)\s*(\d{2})?\s*分?(?:\s*[-~ー−–—]\s*(\d{1,2})\s*(?::|時)?\s*(\d{2})?\s*分?)?/u', $line, $t)
            && (int) $t[1] <= 24) {
            $hourWord = str_contains($t[0], '時');
            $h = (int) $t[1];
            if ($pm && $h < 12) {
                $h += 12;
            }
            $start = Jp::fromMin($h * 60 + (int) ($t[2] ?? 0));
            if (isset($t[3]) && $t[3] !== '' && (int) $t[3] <= 24) {
                $h2 = (int) $t[3];
                if ($pm && $h2 < 12) {
                    $h2 += 12;
                }
                $end = Jp::fromMin($h2 * 60 + (int) ($t[4] ?? 0));
            }
            $line = $this->replaceOnce($t[0], ' ', $line);
            $line = preg_replace('/午後|午前|AM|PM/iu', ' ', $line);
        }

        $title = trim(preg_replace('/\s+/u', ' ', preg_replace('/^[\s・\-–—●○◯□■☐☑✓✔*＊>＞]+/u', '', $line)));

        return compact('date', 'start', 'end', 'title', 'meridiem', 'hourWord');
    }

    /**
     * クイック登録：1行の文章 → 予定の下書き（保存はしない。予定のフォームで確認してから保存する）
     * 読める言葉：今日・明日・明後日・○曜日・今週／来週／再来週○曜日・○月○日・○/○・○日・午前／午後○時・24時間の時刻
     * 読めなかったものは空のままにして、warnings に理由を入れる（勝手に日時を決めない）
     *
     * @return array{title: string, date: ?string, start: ?string, end: ?string, category_id: ?int, warnings: list<string>}
     */
    public function parseQuick(string $text, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $line = trim($this->normalize($text));
        $date = null;
        $warnings = [];

        // 今日・明日・明後日（「明後日」を先に見る）
        foreach (['/明後日|あさって/u' => 2, '/明日|あした/u' => 1, '/今日|きょう/u' => 0] as $re => $add) {
            if (preg_match($re, $line, $m)) {
                $date = $today->addDays($add);
                $line = $this->replaceOnce($m[0], ' ', $line);
                break;
            }
        }
        // 今週／来週／再来週○曜日（週は月曜はじまり）
        if (! $date && preg_match('/(再来週|来週|今週)\s*の?\s*([日月火水木金土])曜(?:日)?/u', $line, $m)) {
            $offset = (array_search($m[2], Jp::WEEK, true) + 6) % 7; // 月曜 = 0 … 日曜 = 6
            $weeks = ['今週' => 0, '来週' => 1, '再来週' => 2][$m[1]];
            $date = $today->startOfWeek(CarbonImmutable::MONDAY)->addWeeks($weeks)->addDays($offset);
            $line = $this->replaceOnce($m[0], ' ', $line);
        } elseif (! $date && preg_match('/([日月火水木金土])曜(?:日)?/u', $line, $m)) {
            // ○曜日だけ：今日を含めて、次にくるその曜日
            $diff = (array_search($m[1], Jp::WEEK, true) - $today->dayOfWeek + 7) % 7;
            $date = $today->addDays($diff);
            $line = $this->replaceOnce($m[0], ' ', $line);
        }

        $p = $this->parseLine(trim($line), $today);
        if ($p['date']) {
            if ($date && $date->format('Y-m-d') !== $p['date']) {
                $warnings[] = '日付が2つ書かれていたので、数字の日付（'.Jp::dateLabel(CarbonImmutable::parse($p['date'])).'）を使いました';
            }
            $date = CarbonImmutable::parse($p['date']);
        }

        $title = mb_substr($p['title'], 0, 80);
        $start = $p['start'] ?: null;
        $end = $start ? ($p['end'] && Jp::toMin($p['end']) > Jp::toMin($start) ? $p['end'] : Jp::fromMin(Jp::toMin($start) + 60)) : null;

        if (! $date) {
            $warnings[] = '日付が読み取れませんでした。日付を選んでください';
        }
        if (! $start) {
            $warnings[] = '時刻が読み取れませんでした。時刻を入れるか「終日」にしてください';
        } elseif ($p['hourWord'] && ! $p['meridiem'] && ($h = intdiv(Jp::toMin($start), 60)) >= 1 && $h <= 6) {
            $warnings[] = "{$h}時は午前{$h}時として読み取りました。午後なら時刻を直してください";
        }
        if ($title === '') {
            $warnings[] = '予定名が読み取れませんでした。予定名を入れてください';
        }
        if ($date && $date->lt($today)) {
            $warnings[] = '過去の日付（'.Jp::dateLabel($date).'）です。日付を確認してください';
        } elseif ($date && $start && $date->isSameDay($today) && Jp::at($today, $start)->lt($now)) {
            $warnings[] = '今日のもう過ぎた時刻（'.$start.'）です。時刻を確認してください';
        }

        return [
            'title' => $title,
            'date' => $date?->format('Y-m-d'),
            'start' => $start,
            'end' => $end,
            'category_id' => $title !== '' ? $this->guessCategory($title) : null,
            'warnings' => $warnings,
        ];
    }

    public function guessCategory(string $title): ?int
    {
        foreach (self::HINTS as $key => $re) {
            if (preg_match($re, $title) && ($c = $this->categories->firstWhere('key', $key))) {
                return $c->id;
            }
        }

        return $this->categories->firstWhere('key', 'private')?->id ?? $this->categories->first()?->id;
    }

    /** 全角の数字・記号を半角にそろえる */
    private function normalize(string $s): string
    {
        $s = mb_convert_kana($s, 'n', 'UTF-8');

        return str_replace(['：', '／', '～', '〜', '時半'], [':', '/', '~', '~', ':30'], $s);
    }

    /** 月だけわかる日付は、基準日から半年以内になる年にする */
    private function makeDate(CarbonImmutable $base, int $mo, int $d): string
    {
        $y = $base->year;
        if ($mo < $base->month - 6) {
            $y++;
        } elseif ($mo > $base->month + 6) {
            $y--;
        }

        return CarbonImmutable::create($y, $mo, 1)->addDays($d - 1)->format('Y-m-d');
    }

    private function replaceOnce(string $search, string $replace, string $subject): string
    {
        $pos = mb_strpos($subject, $search);

        return $pos === false ? $subject : mb_substr($subject, 0, $pos).$replace.mb_substr($subject, $pos + mb_strlen($search));
    }
}
