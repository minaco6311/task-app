<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\Backup;
use App\Services\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 第1段階：完了チェック・今日やること／期限切れ・一覧（7日間）・月表示の予定名
 * 今日 = 2026-11-05(木)
 */
class TaskListsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
        Http::preventStrayRequests(); // 本物のLINEには送らない
        $this->actingAs(User::factory()->create()); // データはユーザーごとなので、先にログインする
        Category::createDefaults();
    }

    private function task(string $title, string $date, ?string $start = null, array $extra = []): Task
    {
        return Task::create(Task::normalizeTimes([
            'title' => $title, 'date' => $date, 'all_day' => $start === null, 'start_time' => $start,
            'category_id' => Category::query()->where('key', 'work')->value('id'),
            'notify_day_before' => true, 'notify_morning' => true, 'notify_before_minutes' => 60, ...$extra,
        ]));
    }

    // ----- 完了チェック -----

    public function test_done_can_be_set_and_undone_without_opening_the_edit_page(): void
    {
        $t = $this->task('資料を送る', '2026-11-05', '10:00');

        $this->from('/?view=month')->patch("/tasks/{$t->id}/done", ['done' => 1])
            ->assertRedirect('/?view=month')->assertSessionHas('status', '完了にしました');
        $this->assertTrue($t->refresh()->done);

        // 2回押しても結果は同じ
        $this->patch("/tasks/{$t->id}/done", ['done' => 1]);
        $this->assertTrue($t->refresh()->done);

        // 画面を読み込み直さない切り替え（JSON）
        $this->patchJson("/tasks/{$t->id}/done", ['done' => 0])->assertOk()
            ->assertExactJson(['id' => $t->id, 'done' => false, 'progress' => ['done' => 0, 'total' => 1, 'percent' => 0]]);
        $this->assertFalse($t->refresh()->done);

        $this->patchJson("/tasks/{$t->id}/done", [])->assertUnprocessable();
        // ほかの項目は変わらない
        $this->assertSame('資料を送る', $t->refresh()->title);
        $this->assertSame('10:00', $t->start);
    }

    public function test_rows_have_a_check_button_separate_from_the_edit_link(): void
    {
        $t = $this->task('チェック対象', '2026-11-05', '10:00', ['done' => true]);

        $html = $this->get('/?view=month&date=2026-11-05')->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('tasks.done', $t).'"', $html);
        $this->assertStringContainsString('href="'.route('tasks.edit', $t).'"', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('「チェック対象」を未完了に戻す', $html);
    }

    public function test_done_stops_line_notifications_and_undo_brings_them_back(): void
    {
        config(['line.driver' => 'line']);
        LineConnection::query()->create(['channel_access_token' => 'test-token-0123456789abcdef', 'line_user_id' => 'U0123456789abcdef0123456789abcdef']);
        Http::fake(['api.line.me/*' => Http::response([])]);
        $t = $this->task('歯医者', '2026-11-05', '14:00');
        $pushes = fn () => Http::recorded(fn (Request $r) => str_contains($r->url(), '/message/push'))->count();
        $run = function (string $at) {
            $this->travelTo(CarbonImmutable::parse($at));
            app(NotificationDispatcher::class)->run();
        };

        $this->patch("/tasks/{$t->id}/done", ['done' => 1]);
        $run('2026-11-05 13:00');
        $this->assertSame(0, $pushes()); // 完了 → 開始前の通知は送らない

        $this->patch("/tasks/{$t->id}/done", ['done' => 0]); // 13:10 に未完了へ戻す（13:00 の通知から30分以内）
        $run('2026-11-05 13:10');
        $this->assertSame(1, $pushes());
        $this->assertSame('sent', NotificationLog::query()->where('type', 'before')->value('status'));
    }

    public function test_completing_a_task_cancels_a_pending_retry(): void
    {
        config(['line.driver' => 'line']);
        LineConnection::query()->create(['channel_access_token' => 'test-token-0123456789abcdef', 'line_user_id' => 'U0123456789abcdef0123456789abcdef']);
        Http::fake(['api.line.me/*' => Http::sequence()->push([], 500)->push([])]);
        $t = $this->task('歯医者', '2026-11-05', '14:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00'));
        app(NotificationDispatcher::class)->run();                // 1回目は失敗（送り直し待ち）
        $this->patchJson("/tasks/{$t->id}/done", ['done' => 1]);  // 送り直しの前に完了
        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:01'));
        app(NotificationDispatcher::class)->run();

        $this->assertSame(1, Http::recorded()->count());
        $this->assertSame('canceled', NotificationLog::query()->where('type', 'before')->value('status'));
    }

    // ----- 今日やること・期限切れ -----

    private function overdueSet(): void
    {
        $this->task('今日の会議', '2026-11-05', '15:00');
        $this->task('今日の買い物', '2026-11-05');
        $this->task('昨日の提出', '2026-11-04', '10:00');
        $this->task('昨日の完了済み', '2026-11-04', '11:00', ['done' => true]);
        $this->task('14日前の手続き', '2026-10-22');       // 過去14日間に入る（10/22〜11/4）
        $this->task('15日前の返却', '2026-10-21');         // 14日より前
        $this->task('来週の予定', '2026-11-12', '10:00');
    }

    public function test_today_and_overdue_in_the_last_14_days(): void
    {
        $this->overdueSet();

        $res = $this->get('/?view=month&date=2026-11-10')->assertOk();

        // 期限切れは（優先度が同じなら）日時の早い順（第2段階で「新しい順」から変更）
        $res->assertSeeInOrder(['今日やること', '今日の買い物', '今日の会議', '期限切れ（未完了）', '14日前の手続き', '昨日の提出']);
        $res->assertDontSee('昨日の完了済み')->assertDontSee('15日前の返却')->assertDontSee('来週の予定');
        $res->assertSee('すべて表示（14日より前に1件）');
        $res->assertViewHas('overdue', fn ($o) => $o['count'] === 2 && $o['older'] === 1 && ! $o['all']);
    }

    public function test_show_all_overdue_and_back_to_14_days(): void
    {
        $this->overdueSet();

        $this->get('/?view=month&date=2026-11-10&overdue=all')->assertOk()
            ->assertSee('15日前の返却')->assertSee('14日間の表示に戻す')
            ->assertViewHas('overdue', fn ($o) => $o['count'] === 3 && $o['all']);

        $this->get('/?view=month&date=2026-11-10')->assertDontSee('15日前の返却');
    }

    public function test_today_section_is_not_repeated_when_today_is_selected(): void
    {
        $this->overdueSet();

        $res = $this->get('/?view=month&date=2026-11-05')->assertOk();

        $res->assertDontSee('今日やること')->assertSee('期限切れ（未完了）');
        $this->assertSame(1, substr_count($res->getContent(), '>今日の会議<'));
    }

    public function test_bulk_complete_shows_the_count_before_completing(): void
    {
        $this->overdueSet();

        $this->get('/overdue/complete?range=14')->assertOk()->assertSee('2件を完了にする')->assertSee('昨日の提出');
        $this->assertSame(0, Task::query()->where('title', '昨日の提出')->where('done', true)->count()); // 確認画面だけでは変わらない

        // 確認したあとに件数が変わっていたら実行しない
        $this->post('/overdue/complete', ['range' => '14', 'count' => 5])->assertRedirect('/overdue/complete?range=14');
        $this->assertFalse(Task::query()->where('title', '昨日の提出')->first()->done);

        $this->post('/overdue/complete', ['range' => '14', 'count' => 2])->assertSessionHas('status', '期限切れの 2件を完了にしました');
        $this->assertTrue(Task::query()->where('title', '昨日の提出')->first()->done);
        $this->assertTrue(Task::query()->where('title', '14日前の手続き')->first()->done);
        $this->assertFalse(Task::query()->where('title', '15日前の返却')->first()->done); // 14日より前は対象外
        $this->assertFalse(Task::query()->where('title', '今日の会議')->first()->done);   // 今日の予定は対象外

        $this->get('/overdue/complete?range=all')->assertSee('1件を完了にする');
    }

    public function test_many_overdue_tasks_are_limited_on_screen(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->task("たまった予定{$i}", CarbonImmutable::parse('2026-11-04')->subDays($i % 14)->format('Y-m-d'));
        }

        $this->get('/?view=month&date=2026-11-05')->assertOk()
            ->assertViewHas('overdue', fn ($o) => $o['count'] === 120 && $o['tasks']->count() === 100)
            ->assertSee('ほか20件');
    }

    // ----- 一覧（7日間） -----

    public function test_list_shows_seven_days_in_time_order(): void
    {
        $this->task('木曜の夕方', '2026-11-05', '18:00');
        $this->task('木曜の朝', '2026-11-05', '08:30');
        $this->task('木曜の終日', '2026-11-05');
        $this->task('土曜の予定', '2026-11-07', '10:00');
        $this->task('水曜の予定', '2026-11-11', '09:00');   // 7日目（11/5〜11/11）
        $this->task('8日後の予定', '2026-11-12', '09:00');  // 範囲外
        $this->task('昨日の予定', '2026-11-04', '09:00');   // 範囲外

        $res = $this->get('/?view=list')->assertOk();

        $res->assertSee('11/5 – 11/11')
            ->assertSeeInOrder(['11月5日(木)', '木曜の終日', '木曜の朝', '木曜の夕方', '11月6日(金)', '予定なし', '11月7日(土)', '土曜の予定', '11月11日(水)', '水曜の予定'])
            ->assertDontSee('8日後の予定')->assertDontSee('昨日の予定')
            ->assertSee('仕事')                                   // カテゴリ
            ->assertSee(route('tasks.done', Task::query()->where('title', '木曜の朝')->first()));

        // 次へ・前へは7日ずつ
        $res->assertViewHas('next', '2026-11-12')->assertViewHas('prev', '2026-10-29');
    }

    public function test_switching_between_month_week_day_and_list(): void
    {
        $this->task('切り替え確認', '2026-11-05', '10:00');

        foreach (['month', 'week', 'day', 'list'] as $v) {
            $this->get("/?view={$v}&date=2026-11-05")->assertOk()->assertSee('切り替え確認')->assertSee('一覧');
        }
        // 最後に見ていた表示を覚えている
        $this->get('/')->assertViewHas('view', 'list');
        $this->get('/?view=month')->assertViewHas('view', 'month');
        $this->get('/?view=unknown')->assertViewHas('view', 'month');
    }

    // ----- 月表示の予定名 -----

    public function test_month_titles_are_hidden_by_default_and_can_be_shown(): void
    {
        foreach (range(1, 5) as $i) {
            $this->task("会議{$i}", '2026-11-05', sprintf('%02d:00', 9 + $i));
        }

        $off = $this->get('/?view=month&date=2026-11-05')->getContent();
        $this->assertSame(4, substr_count($off, 'class="bar"'));       // 今までどおり色の線（4本＋「+1」）
        $this->assertStringNotContainsString('mcell-title', $off);
        $this->assertStringContainsString('>+1<', $off);

        $this->put('/settings/display', ['month_show_titles' => '1'])->assertRedirect();
        $this->assertSame(1, Setting::values()['month_show_titles']);

        $on = $this->get('/?view=month&date=2026-11-05')->getContent();
        $this->assertSame(3, substr_count($on, 'class="mcell-title"'));
        $this->assertStringContainsString('＋2件', $on);
        $this->assertStringContainsString('style="--c: #', $on);         // カテゴリの色はそのまま

        $this->put('/settings/display', [])->assertRedirect();         // チェックを外して保存
        $this->assertSame(0, Setting::values()['month_show_titles']);
    }

    public function test_long_titles_are_shortened_in_the_month_cell(): void
    {
        Setting::put('month_show_titles', 1);
        $this->task('とても長い予定の名前がここに入ります', '2026-11-05', '10:00');

        $this->get('/?view=month&date=2026-11-05')->assertSee('とても長い予定…');
    }

    // ----- 既存の機能との関係 -----

    public function test_backup_keeps_the_month_setting_and_old_backups_still_restore(): void
    {
        Setting::put('month_show_titles', 1);
        $this->task('戻す予定', '2026-11-06', '10:00', ['done' => true]);
        $json = Backup::export();
        $this->assertSame(1, $json['settings']['month_show_titles']);

        Backup::restore($json);
        $this->assertSame(1, Setting::values()['month_show_titles']);
        $this->assertTrue(Task::query()->where('title', '戻す予定')->first()->done);

        // 前の形式（month_show_titles がない）でも戻せる → 標準（出さない）
        unset($json['settings']['month_show_titles']);
        Backup::restore($json);
        $this->assertSame(0, Setting::values()['month_show_titles']);
    }

    public function test_google_calendar_and_editing_still_work(): void
    {
        $t = $this->task('Google確認', '2026-11-05', '10:00');

        $this->post("/tasks/{$t->id}/google")->assertRedirectContains('calendar.google.com');
        $this->assertTrue($t->refresh()->gcal_added);
        $this->get("/tasks/{$t->id}/edit")->assertOk()->assertSee('完了にする');
    }
}
