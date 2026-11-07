<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\TaskTextParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 手帳の文字 → 予定の候補（試作版と同じ例で確認） */
class TaskTextParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create()); // カテゴリはユーザーごと
    }

    public function test_parses_dates_times_and_categories(): void
    {
        $cats = Category::ordered();
        $id = fn (string $key) => $cats->firstWhere('key', $key)->id;

        $text = implode("\n", [
            '10/27(火)',
            '14:00 歯医者',
            '・19:00-20:00 ジム',
            '資料の提出',
            '１０月２８日 午後3時半 打ち合わせ',
            '29日 10時〜11時30分 会議',
            '11/2 区役所',
        ]);
        $out = (new TaskTextParser($cats))->parse($text, CarbonImmutable::create(2026, 10, 25));

        $simple = array_map(fn ($c) => [$c['date'], $c['start'], $c['end'], $c['title'], $c['category_id']], $out);
        $this->assertSame([
            ['2026-10-27', '14:00', '15:00', '歯医者', $id('health')],
            ['2026-10-27', '19:00', '20:00', 'ジム', $id('health')],
            ['2026-10-27', '', '', '資料の提出', $id('work')],
            ['2026-10-28', '15:30', '16:30', '打ち合わせ', $id('work')],
            ['2026-10-29', '10:00', '11:30', '会議', $id('work')],
            ['2026-11-02', '', '', '区役所', $id('errand')],
        ], $simple);
    }

    public function test_month_near_year_boundary_goes_to_next_year(): void
    {
        $out = (new TaskTextParser(Category::ordered()))->parse('1/5 初詣', CarbonImmutable::create(2026, 12, 20));

        $this->assertSame('2027-01-05', $out[0]['date']);
        $this->assertSame(Category::query()->where('key', 'private')->value('id'), $out[0]['category_id']);
    }
}
