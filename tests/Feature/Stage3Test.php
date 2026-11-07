<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\TaskSeries;
use App\Models\TaskSeriesSkip;
use App\Models\User;
use App\Services\Backup;
use App\Services\GoogleCalendar;
use App\Services\Line\SendQuota;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use App\Services\Recurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 第3段階：繰り返し予定（④）・再通知とスヌーズ（③）・月の送信数
 * 今日 = 2026-11-05(木) 9:00。90日先 = 2027-02-03
 */
class Stage3Test extends TestCase
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

    private function form(array $extra = []): array
    {
        return [
            'title' => '掃除', 'date' => '2026-11-05', 'start_time' => '14:00', 'end_time' => '15:00',
            'category_id' => Category::query()->where('key', 'errand')->value('id'),
            'notify_day_before' => '1', 'notify_morning' => '1', 'notify_before_minutes' => 60, ...$extra,
        ];
    }

    private function task(string $title, string $date, ?string $start = null, array $extra = []): Task
    {
        return Task::create(Task::normalizeTimes([
            'title' => $title, 'date' => $date, 'all_day' => $start === null, 'start_time' => $start,
            'notify_day_before' => true, 'notify_morning' => true, 'notify_before_minutes' => 60, ...$extra,
        ]));
    }

    /** @return list<string> */
    private function dates(?int $seriesId = null): array
    {
        return Task::query()->when($seriesId, fn ($q) => $q->where('series_id', $seriesId))
            ->orderBy('date')->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->format('Y-m-d'))->all();
    }

    private function useLine(array $fakes = []): void
    {
        config(['line.driver' => 'line']);
        LineConnection::query()->create(['channel_access_token' => 'test-token-0123456789abcdef', 'line_user_id' => 'U0123456789abcdef0123456789abcdef']);
        Http::fake([
            'api.line.me/v2/bot/message/push' => Http::response([]),
            'api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 0]),
            'api.line.me/v2/bot/message/quota' => Http::response(['type' => 'limited', 'value' => 200]),
            ...$fakes,
        ]);
    }

    private function pushes(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/message/push'))->map(fn ($p) => $p[0]['messages'][0]['text'])->values()->all();
    }

    private function run_(string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
        app(NotificationDispatcher::class)->run();
    }

    // ===================== ④ 繰り返し予定 =====================

    public function test_daily_series_is_generated_90_days_ahead_without_duplicates(): void
    {
        $this->post('/tasks', $this->form(['repeat' => 'daily']))->assertRedirect()->assertSessionHas('status', '繰り返しの予定を追加しました');

        $s = TaskSeries::query()->sole();
        $dates = $this->dates($s->id);
        $this->assertCount(91, $dates);                                  // 11/5 〜 2/3
        $this->assertSame(['2026-11-05', '2027-02-03'], [$dates[0], end($dates)]);
        $this->assertSame($dates, array_values(array_unique($dates)));
        $this->assertSame('2027-02-03', $s->generated_until->format('Y-m-d'));
        $this->assertSame('14:00', Task::query()->whereDate('date', '2026-12-24')->first()->start);

        // 毎日の自動処理：30日後に動くと先へ延び、もう一度動いても増えない
        $this->travelTo(CarbonImmutable::parse('2026-12-05 00:10'));
        $this->artisan('tasks:fill-recurring')->expectsOutputToContain('30件')->assertSuccessful();
        $this->artisan('tasks:fill-recurring')->expectsOutputToContain('0件')->assertSuccessful();
        $this->assertSame(121, Task::count());

        // データベースでも同じ回は1つだけ
        $this->expectException(UniqueConstraintViolationException::class);
        Task::create(['title' => '重複', 'date' => '2026-12-24', 'all_day' => true, 'series_id' => $s->id, 'series_date' => '2026-12-24']);
    }

    public function test_weekly_on_several_days_and_weekdays_only(): void
    {
        $this->post('/tasks', $this->form(['title' => '月水金', 'date' => '2026-11-06', 'repeat' => 'weekly', 'repeat_weekdays' => ['1', '3', '5']]));
        $w = TaskSeries::query()->where('title', '月水金')->sole();
        $this->assertSame(['2026-11-06', '2026-11-09', '2026-11-11', '2026-11-13', '2026-11-16'], array_slice($this->dates($w->id), 0, 5));
        $this->assertSame('毎週 月・水・金', $w->label());

        $this->post('/tasks', $this->form(['title' => '平日', 'date' => '2026-11-06', 'repeat' => 'weekdays']));
        $d = TaskSeries::query()->where('title', '平日')->sole();
        $this->assertSame(['2026-11-06', '2026-11-09', '2026-11-10', '2026-11-11'], array_slice($this->dates($d->id), 0, 4)); // 土日は飛ばす

        $this->post('/tasks', $this->form(['title' => '曜日なし', 'repeat' => 'weekly']))->assertSessionHasErrors('repeat_weekdays');
    }

    public function test_monthly_on_the_31st_uses_the_last_day_and_keeps_31(): void
    {
        // 10/31 から毎月 → 11月は30日、12月は31日に戻る、1月は31日
        $this->post('/tasks', $this->form(['title' => '月末の支払い', 'date' => '2026-10-31', 'repeat' => 'monthly']));
        $s = TaskSeries::query()->sole();
        $this->assertSame(31, $s->month_day);
        $this->assertSame(['2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31'], $this->dates($s->id));

        // うるう年の2月
        $m = new TaskSeries(['freq' => 'monthly', 'month_day' => 31, 'starts_on' => '2027-12-31']);
        $this->assertSame(['2027-12-31', '2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30'],
            Recurrence::dates($m, CarbonImmutable::parse('2027-12-01'), CarbonImmutable::parse('2028-04-30')));
        $n = new TaskSeries(['freq' => 'monthly', 'month_day' => 31, 'starts_on' => '2027-01-31']);
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], Recurrence::dates($n, CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-03-31')));
    }

    public function test_series_with_an_end_date(): void
    {
        $this->post('/tasks', $this->form(['repeat' => 'daily', 'repeat_end' => 'until', 'repeat_until' => '2026-11-10']));
        $this->assertSame(['2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10'], $this->dates());
        $this->assertSame('・11月10日(火)まで', mb_substr(TaskSeries::query()->sole()->label(), 2));

        $this->post('/tasks', $this->form(['repeat' => 'daily', 'repeat_end' => 'until', 'repeat_until' => '2026-11-01']))->assertSessionHasErrors('repeat_until');
        $this->post('/tasks', $this->form(['repeat' => 'daily', 'repeat_end' => 'until']))->assertSessionHasErrors('repeat_until');
    }

    public function test_editing_only_this_occurrence_is_not_overwritten(): void
    {
        $this->post('/tasks', $this->form(['repeat' => 'daily']));
        $t = Task::query()->whereDate('date', '2026-11-12')->first();

        $this->put("/tasks/{$t->id}", $this->form(['title' => '大掃除', 'date' => '2026-11-12', 'start_time' => '10:00', 'end_time' => '12:00', 'repeat' => 'daily', 'scope' => 'this']))
            ->assertSessionHas('status', 'この予定だけ保存しました');

        $t->refresh();
        $this->assertSame(['大掃除', '10:00', true], [$t->title, $t->start, $t->series_modified]);
        $this->assertSame('掃除', Task::query()->whereDate('date', '2026-11-13')->value('title')); // 翌日以降は変わらない
        $this->assertSame('14:00', Task::query()->whereDate('date', '2026-11-13')->first()->start);

        $this->travelTo(CarbonImmutable::parse('2026-11-06 00:10'));
        $this->artisan('tasks:fill-recurring');
        $this->assertSame('大掃除', $t->refresh()->title); // 自動処理で上書きされない
        $this->assertSame(1, Task::query()->whereDate('date', '2026-11-12')->count());
    }

    public function test_deleting_only_this_occurrence_does_not_come_back(): void
    {
        $this->post('/tasks', $this->form(['repeat' => 'daily']));
        $t = Task::query()->whereDate('date', '2026-11-12')->first();

        $this->delete("/tasks/{$t->id}", ['scope' => 'this'])->assertSessionHas('status', 'この予定だけ削除しました');
        $this->assertModelMissing($t);
        $this->assertTrue(TaskSeriesSkip::query()->whereDate('date', '2026-11-12')->exists());

        $this->travelTo(CarbonImmutable::parse('2026-11-06 00:10'));
        $this->artisan('tasks:fill-recurring');
        $this->assertSame(0, Task::query()->whereDate('date', '2026-11-12')->count());
        $this->assertSame(1, Task::query()->whereDate('date', '2026-11-13')->count());
    }

    public function test_editing_this_and_following_keeps_the_past_and_ids(): void
    {
        $this->post('/tasks', $this->form(['title' => '週3', 'date' => '2026-11-06', 'repeat' => 'weekly', 'repeat_weekdays' => ['1', '3', '5']]));
        $old = TaskSeries::query()->sole();
        $first = Task::query()->whereDate('date', '2026-11-06')->first();
        $wed = Task::query()->whereDate('date', '2026-11-11')->first();
        $fri = Task::query()->whereDate('date', '2026-11-13')->first();
        $fri->update(['done' => true]);
        $count = Task::count();

        $this->put("/tasks/{$wed->id}", $this->form(['title' => '週3（新）', 'date' => '2026-11-11', 'repeat' => 'weekly', 'repeat_weekdays' => ['1', '3', '5'], 'scope' => 'following']))
            ->assertSessionHas('status', 'この予定以後すべてを保存しました');

        $this->assertSame('週3', $first->refresh()->title);                     // 前の回はそのまま
        $this->assertSame($old->id, $first->series_id);
        $this->assertSame('2026-11-10', $old->refresh()->until->format('Y-m-d')); // 前の繰り返しは前日で終わり
        $this->assertSame('週3（新）', $wed->refresh()->title);
        $this->assertSame(['週3（新）', true], [$fri->refresh()->title, $fri->done]); // 同じ ID・完了の状態はそのまま
        $this->assertSame($wed->series_id, $fri->series_id);
        $this->assertNotSame($old->id, $wed->series_id);
        $this->assertSame($count, Task::count());
    }

    public function test_changing_the_days_for_following_occurrences(): void
    {
        $this->post('/tasks', $this->form(['title' => '週3', 'date' => '2026-11-06', 'repeat' => 'weekly', 'repeat_weekdays' => ['1', '3', '5']]));
        $wed = Task::query()->whereDate('date', '2026-11-11')->first();

        // 11/11 以後を「火・木」に変える
        $this->put("/tasks/{$wed->id}", $this->form(['date' => '2026-11-11', 'repeat' => 'weekly', 'repeat_weekdays' => ['2', '4'], 'scope' => 'following']));

        $this->assertSame(['2026-11-06', '2026-11-09', '2026-11-11', '2026-11-12', '2026-11-17', '2026-11-19'], array_slice($this->dates(), 0, 6));
        $this->assertSame(0, Task::query()->whereDate('date', '2026-11-13')->count());

        // 以後の繰り返しをやめる → この回だけ残る
        $tue = Task::query()->whereDate('date', '2026-11-17')->first();
        $this->put("/tasks/{$tue->id}", $this->form(['date' => '2026-11-17', 'repeat' => 'none', 'scope' => 'following']));
        $this->assertNull($tue->refresh()->series_id);
        $this->assertSame('2026-11-17', Task::query()->orderByDesc('date')->value('date') ? CarbonImmutable::parse(Task::query()->orderByDesc('date')->value('date'))->format('Y-m-d') : null);
    }

    public function test_deleting_this_and_following_keeps_the_past(): void
    {
        $this->post('/tasks', $this->form(['repeat' => 'daily']));
        $t = Task::query()->whereDate('date', '2026-11-12')->first();

        $this->delete("/tasks/{$t->id}", ['scope' => 'following'])->assertSessionHas('status', 'この予定以後すべてを削除しました');

        $this->assertSame(['2026-11-05', '2026-11-11'], [$this->dates()[0], last($this->dates())]);
        $this->travelTo(CarbonImmutable::parse('2026-11-06 00:10'));
        $this->artisan('tasks:fill-recurring');
        $this->assertSame(0, Task::query()->where('date', '>=', '2026-11-12')->count());
    }

    public function test_subtasks_are_copied_and_completed_separately(): void
    {
        $this->post('/tasks', $this->form(['title' => '掃除', 'date' => '2026-11-09', 'repeat' => 'weekly', 'repeat_weekdays' => ['1'],
            'subtasks' => [['title' => '床を掃除する'], ['title' => 'ゴミをまとめる'], ['title' => '洗面台を掃除する']]]));
        [$a, $b] = Task::query()->orderBy('date')->take(2)->get()->all();
        $this->assertSame(['床を掃除する', 'ゴミをまとめる', '洗面台を掃除する'], $b->subtasks->pluck('title')->all());

        $this->patchJson("/subtasks/{$a->subtasks[0]->id}/done", ['done' => 1])->assertOk();

        $this->assertTrue($a->subtasks()->first()->done);
        $this->assertSame([false, false, false], $b->subtasks()->pluck('done')->map(fn ($v) => (bool) $v)->all()); // 来週の分は未完了のまま
    }

    public function test_google_calendar_registers_one_occurrence_at_a_time(): void
    {
        $this->post('/tasks', $this->form(['repeat' => 'daily']));
        $t = Task::query()->whereDate('date', '2026-11-12')->first();

        $this->post("/tasks/{$t->id}/google")->assertRedirectContains('dates=20261112T140000%2F20261112T150000');
        $this->assertStringNotContainsString('recur', GoogleCalendar::url($t));
        $this->get('/export/ics')->assertOk()->assertSee('DTSTART;TZID=Asia/Tokyo:20261112T140000')->assertSee("UID:task-{$t->id}@", false);
    }

    public function test_regenerating_following_does_not_resend_line(): void
    {
        $this->useLine();
        $this->post('/tasks', $this->form(['repeat' => 'daily'])); // 今日 14:00 から毎日
        $today = Task::query()->whereDate('date', '2026-11-05')->first();

        $this->run_('2026-11-05 13:00'); // 今日の回の60分前の通知
        $this->assertCount(1, $this->pushes());

        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:10'));
        $this->put("/tasks/{$today->id}", $this->form(['title' => '掃除（変更）', 'repeat' => 'daily', 'scope' => 'following']));
        $this->run_('2026-11-05 13:11');

        $this->assertCount(1, $this->pushes()); // 同じ予定（ID・時刻が同じ）なので送り直さない
        $this->assertSame($today->id, Task::query()->whereDate('date', '2026-11-05')->value('id'));
    }

    // ===================== ③ 再通知・スヌーズ =====================

    public function test_snooze_10_minutes_1_hour_and_tomorrow_morning(): void
    {
        $t = $this->task('歯医者', '2026-11-05', '14:00');

        $this->post("/tasks/{$t->id}/reminders", ['when' => '10'])->assertSessionHas('status', '11/5(木) 09:10 に再通知します');
        $this->post("/tasks/{$t->id}/reminders", ['when' => '60']);
        $this->post("/tasks/{$t->id}/reminders", ['when' => 'tomorrow']);
        $this->assertSame(['2026-11-05 09:10', '2026-11-05 10:00', '2026-11-06 08:00'], $t->reminders()->get()->map(fn ($r) => $r->remind_at->format('Y-m-d H:i'))->all());
        $this->post("/tasks/{$t->id}/reminders", ['when' => 'later'])->assertSessionHasErrors('when');

        // シミュレーション（LINE未接続）で送る
        $this->run_('2026-11-05 09:10');
        $log = NotificationLog::query()->where('type', 'snooze')->sole();
        $this->assertSame('simulated', $log->status);
        $this->assertSame("🔁 再通知\n・11月5日(木) 14:00〜 歯医者", $log->message);
        Http::assertNothingSent();

        $this->get("/tasks/{$t->id}/edit")->assertSee('あとで再通知')->assertSee('11/5(木) 10:00')->assertSee('11/6(金) 08:00');
    }

    public function test_snooze_is_not_sent_after_done_delete_or_time_change(): void
    {
        $done = $this->task('完了する', '2026-11-05', '14:00');
        $moved = $this->task('時刻を変える', '2026-11-05', '15:00');
        $gone = $this->task('消す', '2026-11-05', '16:00');
        foreach ([$done, $moved, $gone] as $t) {
            $this->post("/tasks/{$t->id}/reminders", ['when' => '10']);
        }

        $this->patch("/tasks/{$done->id}/done", ['done' => 1]);
        $this->put("/tasks/{$moved->id}", $this->form(['title' => '時刻を変える', 'start_time' => '17:00', 'end_time' => '18:00']));
        $this->delete("/tasks/{$gone->id}");
        $this->post("/tasks/{$done->id}/reminders", ['when' => '10'])->assertSessionHas('status', '完了した予定には再通知を予約できません');

        $this->run_('2026-11-05 09:10');
        $this->assertSame(0, NotificationLog::query()->where('type', 'snooze')->count());
        $this->assertSame(1, TaskReminder::count()); // 完了した予定の予約だけ残る（送らない）
    }

    public function test_remind_until_done_schedule_limits_and_quiet_hours(): void
    {
        $planner = new NotificationPlanner(Setting::values());
        $fmt = fn (Task $t) => array_map(fn ($at) => $at->format('m/d H:i'), $planner->nagTimes($t));

        // 14:00 開始：1時間おき・1日3回まで → 翌朝7時から続けて合計5回
        $this->assertSame([1 => '11/05 15:00', '11/05 16:00', '11/05 17:00', '11/06 07:00', '11/06 08:00'],
            $fmt($this->task('A', '2026-11-05', '14:00', ['remind_until_done' => true])));
        // 20:00 開始：21時のあと 22時〜7時は送らない
        $this->assertSame([1 => '11/05 21:00', '11/06 07:00', '11/06 08:00', '11/06 09:00', '11/07 07:00'],
            $fmt($this->task('B', '2026-11-05', '20:00', ['remind_until_done' => true])));
        // 終日：当日朝の通知の時刻（8:00）から
        $this->assertSame([1 => '11/05 09:00', '11/05 10:00', '11/05 11:00', '11/06 07:00', '11/06 08:00'],
            $fmt($this->task('C', '2026-11-05', null, ['remind_until_done' => true])));
    }

    public function test_remind_until_done_sends_until_completed(): void
    {
        $this->post('/tasks', $this->form(['title' => '書類を出す', 'start_time' => '10:00', 'end_time' => '11:00', 'remind_until_done' => '1', 'notify_before_minutes' => 0]));
        $t = Task::query()->sole();
        $this->assertTrue($t->remind_until_done);

        $this->run_('2026-11-05 11:00');
        $this->run_('2026-11-05 12:00');
        $this->assertSame(2, NotificationLog::query()->where('type', 'nag')->where('status', 'simulated')->count());
        $this->assertStringContainsString('🔁 まだ完了していません（再通知 2/5）', NotificationLog::query()->where('dedupe_key', "nag:{$t->id}:2")->value('message'));

        $this->patch("/tasks/{$t->id}/done", ['done' => 1]); // 完了 → 止まる
        $this->run_('2026-11-05 13:00');
        $this->run_('2026-11-06 07:00');
        $this->assertSame(2, NotificationLog::query()->where('type', 'nag')->count());

        // オフ（既定）の予定には送らない
        $this->task('オフ', '2026-11-05', '10:00');
        $this->run_('2026-11-05 11:00');
        $this->assertSame(2, NotificationLog::query()->where('type', 'nag')->count());
    }

    public function test_reminder_caps_count_only_reminders_and_quiet_hours_block_late_sends(): void
    {
        $t = $this->task('上限', '2026-11-05', '14:00', ['remind_until_done' => true, 'notify_before_minutes' => 0]);
        // 開始時刻を変える前に送った再通知が、すでに5回（別のキー）
        foreach (range(1, 5) as $i) {
            NotificationLog::query()->create(['dedupe_key' => "nag-old:{$i}", 'type' => 'nag', 'task_id' => $t->id, 'scheduled_at' => '2026-11-04 1'.$i.':00',
                'status' => 'simulated', 'message' => 'x']);
        }
        $this->run_('2026-11-05 15:00');
        $this->assertSame('再通知の上限（合計5回）に達しました', NotificationLog::query()->where('dedupe_key', "nag:{$t->id}:1")->value('last_error'));

        // 1日3回
        $u = $this->task('1日3回', '2026-11-05', '14:00', ['remind_until_done' => true, 'notify_before_minutes' => 0]);
        foreach (range(1, 3) as $i) {
            NotificationLog::query()->create(['dedupe_key' => "nag-x:{$i}", 'type' => 'nag', 'task_id' => $u->id, 'scheduled_at' => '2026-11-05 0'.(6 + $i).':30',
                'status' => 'sent', 'sent_at' => '2026-11-05 0'.(6 + $i).':30', 'message' => 'x']);
        }
        $this->run_('2026-11-05 16:00');
        $this->assertSame('再通知の上限（1日3回）に達しました', NotificationLog::query()->where('dedupe_key', "nag:{$u->id}:2")->value('last_error'));

        // 21:00 の再通知が遅れて 22:05 になった → 夜間は送らない（見送りの猶予を90分に広げて確かめる）
        config(['line.grace_minutes' => 90]);
        $v = $this->task('夜', '2026-11-05', '20:00', ['remind_until_done' => true, 'notify_before_minutes' => 0]);
        $this->run_('2026-11-05 22:05');
        $this->assertSame('skipped', NotificationLog::query()->where('dedupe_key', "nag:{$v->id}:1")->value('status'));
        $this->assertStringContainsString('22時〜7時', NotificationLog::query()->where('dedupe_key', "nag:{$v->id}:1")->value('last_error'));
    }

    public function test_reminders_at_the_same_time_as_normal_notifications_are_skipped(): void
    {
        // 7:00 開始 → 完了まで再通知の1回目 8:00 が「当日朝」と同じ時刻
        $early = $this->task('朝の予定', '2026-11-06', '07:00', ['remind_until_done' => true]);
        // スヌーズが「開始前（13:00）」と同じ時刻
        $dentist = $this->task('歯医者', '2026-11-06', '14:00');
        TaskReminder::query()->create(['task_id' => $dentist->id, 'remind_at' => '2026-11-06 13:00']);

        $this->run_('2026-11-06 08:00');
        $this->run_('2026-11-06 13:00');

        $this->assertSame('skipped', NotificationLog::query()->where('dedupe_key', "nag:{$early->id}:1")->value('status'));
        $this->assertSame('skipped', NotificationLog::query()->where('type', 'snooze')->value('status'));
        $this->assertSame('simulated', NotificationLog::query()->where('dedupe_key', 'morning:2026-11-06')->value('status'));
        $this->assertSame(1, NotificationLog::query()->where('type', 'before')->where('status', 'simulated')->count());
    }

    public function test_reminders_stop_at_180_sends_but_normal_notifications_continue(): void
    {
        // LINE側の集計が180通
        $this->useLine(['api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 180])]);
        $t = $this->task('書類', '2026-11-05', '14:00', ['remind_until_done' => true]);
        TaskReminder::query()->create(['task_id' => $t->id, 'remind_at' => '2026-11-05 15:30']);

        $this->run_('2026-11-05 13:00'); // 開始前（通常）→ 送る
        $this->run_('2026-11-05 15:00'); // 完了まで再通知 → 止める
        $this->run_('2026-11-05 15:30'); // スヌーズ（利用者が予約）→ 送る

        $this->assertCount(2, $this->pushes());
        $this->assertStringContainsString('180通に達した', NotificationLog::query()->where('type', 'nag')->value('last_error'));
        $this->get('/settings')->assertSee('今月のLINE送信数：')->assertSee('180通')->assertSee('再通知を停止中');
    }

    public function test_app_count_is_used_when_line_cannot_tell_and_simulation_is_not_counted(): void
    {
        // LINE から取れない（エラー）→ アプリで数えた180通で止める
        $this->useLine(['api.line.me/v2/bot/message/quota/consumption' => Http::response([], 500)]);
        foreach (range(1, 180) as $i) {
            NotificationLog::query()->create(['dedupe_key' => "old:{$i}", 'type' => 'before', 'scheduled_at' => '2026-11-02 10:00', 'status' => 'sent', 'sent_at' => '2026-11-02 10:00', 'message' => 'x']);
        }
        NotificationLog::query()->create(['dedupe_key' => 'last-month', 'type' => 'before', 'scheduled_at' => '2026-10-31 10:00', 'status' => 'sent', 'sent_at' => '2026-10-31 10:00', 'message' => 'x']);
        $quota = app(SendQuota::class);
        $this->assertSame(180, $quota->appCount()); // 先月の分は数えない
        $this->assertTrue($quota->remindersStopped());

        // シミュレーションでは、どれだけ記録があっても止めない
        config(['line.driver' => 'log']);
        NotificationLog::query()->where('status', 'sent')->update(['status' => 'simulated']);
        $this->assertSame(0, app(SendQuota::class)->appCount());
        $this->assertFalse(app(SendQuota::class)->remindersStopped());
    }

    public function test_a_retried_message_is_counted_once(): void
    {
        $this->useLine(['api.line.me/v2/bot/message/push' => Http::sequence()->push([], 500)->push(['message' => 'The retry key is already accepted'], 409)]);
        $this->task('歯医者', '2026-11-05', '14:00');

        $this->run_('2026-11-05 13:00');
        $this->run_('2026-11-05 13:01');

        $this->assertSame(2, NotificationLog::query()->where('type', 'before')->value('attempts'));
        $this->assertSame(1, app(SendQuota::class)->appCount());
    }

    // ===================== コミット前の修正（個別変更の保持・夜間のスヌーズ・上限） =====================

    /** 週1（月曜）の繰り返しを作り、11/16 を個別に変更、11/23 を個別に削除する */
    private function weeklyWithChanges(): array
    {
        $this->post('/tasks', $this->form(['title' => '週1', 'date' => '2026-11-09', 'repeat' => 'weekly', 'repeat_weekdays' => ['1']]));
        $mod = Task::query()->whereDate('date', '2026-11-16')->first();
        $this->put("/tasks/{$mod->id}", $this->form(['title' => '週1（特別）', 'date' => '2026-11-16', 'start_time' => '10:00', 'end_time' => '11:00',
            'repeat' => 'weekly', 'repeat_weekdays' => ['1'], 'scope' => 'this']));
        $del = Task::query()->whereDate('date', '2026-11-23')->first();
        $this->delete("/tasks/{$del->id}", ['scope' => 'this']);

        return [Task::query()->whereDate('date', '2026-11-09')->first(), $mod->refresh()];
    }

    public function test_following_keeps_individual_changes_and_deleted_occurrences(): void
    {
        [$first, $mod] = $this->weeklyWithChanges();

        $this->put("/tasks/{$first->id}", $this->form(['title' => '週1（新）', 'date' => '2026-11-09', 'repeat' => 'weekly', 'repeat_weekdays' => ['1'], 'scope' => 'following']))
            ->assertSessionHas('status', 'この予定以後すべてを保存しました（個別に変更した1件はそのまま残しました）');

        $this->assertSame(['週1（特別）', '10:00', true], [$mod->refresh()->title, $mod->start, $mod->series_modified]); // 個別の変更は残る
        $this->assertSame($first->refresh()->series_id, $mod->series_id);
        $this->assertSame('週1（新）', Task::query()->whereDate('date', '2026-11-30')->value('title'));
        $this->assertSame(0, Task::query()->whereDate('date', '2026-11-23')->count()); // 消した回は復活しない
        $this->travelTo(CarbonImmutable::parse('2026-11-06 00:10'));
        $this->artisan('tasks:fill-recurring');
        $this->assertSame(0, Task::query()->whereDate('date', '2026-11-23')->count());

        // 曜日を変えて、個別に変更した回が新しいルールに合わなくなった → 繰り返しから外して残す
        $this->put("/tasks/{$first->id}", $this->form(['title' => '週1（新）', 'date' => '2026-11-09', 'repeat' => 'weekly', 'repeat_weekdays' => ['1', '2'], 'scope' => 'following']));
        $this->put("/tasks/{$first->id}", $this->form(['title' => '火曜', 'date' => '2026-11-09', 'repeat' => 'weekly', 'repeat_weekdays' => ['2'], 'scope' => 'following']))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '個別に変更した1件は、繰り返しから外して残しました'));
        $this->assertSame(['週1（特別）', null], [$mod->refresh()->title, $mod->series_id]);
    }

    public function test_overwriting_individual_changes_needs_confirmation(): void
    {
        [$first, $mod] = $this->weeklyWithChanges();
        $input = $this->form(['title' => '週1（新）', 'date' => '2026-11-09', 'repeat' => 'weekly', 'repeat_weekdays' => ['1'], 'scope' => 'following', 'overwrite_modified' => '1']);

        $this->get("/tasks/{$first->id}/edit")->assertSee('1件</b> は「この予定だけ」で個別に変更されています', false)->assertSee('個別に変更した1件も上書きする');

        // 1回目：確認画面だけ（件数・いまの内容・上書き後の内容）。まだ何も変わらない
        $this->put("/tasks/{$first->id}", $input)->assertOk()
            ->assertSee('個別に変更した予定が')->assertSee('1件')->assertSee('11月16日(月)')->assertSee('週1（特別）')
            ->assertSee('週1（新）')->assertSee('14:00–15:00')->assertSee('毎週 月')->assertSee('まだ保存していません')
            ->assertSee('name="confirmed" value="1"', false)->assertSee('name="scope" value="following"', false);
        $this->assertSame('週1', $first->refresh()->title);
        $this->assertSame('週1（特別）', $mod->refresh()->title);

        // 「個別の変更は残して保存」
        $this->put("/tasks/{$first->id}", [...$input, 'confirmed' => '1', 'overwrite_modified' => '0']);
        $this->assertSame(['週1（新）', '週1（特別）'], [$first->refresh()->title, $mod->refresh()->title]);

        // 「上書きして保存」
        $this->put("/tasks/{$first->id}", [...$input, 'title' => '週1（全部）', 'confirmed' => '1'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '個別に変更した1件も上書きしました'));
        $this->assertSame(['週1（全部）', '14:00', false], [$mod->refresh()->title, $mod->start, $mod->series_modified]);
        $this->assertSame(0, Task::query()->whereDate('date', '2026-11-23')->count()); // 上書きしても消した回は戻らない
    }

    public function test_night_snooze_needs_confirmation(): void
    {
        $t = $this->task('寝る前の薬', '2026-11-05', '22:30');
        $this->travelTo(CarbonImmutable::parse('2026-11-05 21:55'));

        $this->get("/tasks/{$t->id}/edit")->assertSee('22:05')->assertSee('🌙');

        // 10分後 = 22:05（夜間）→ まだ予約せず確認
        $this->from("/tasks/{$t->id}/edit")->post("/tasks/{$t->id}/reminders", ['when' => '10'])
            ->assertSessionHas('snooze_night', ['when' => '10', 'at' => '11/5(木) 22:05']);
        $this->assertSame(0, TaskReminder::count());
        $this->withSession(['snooze_night' => ['when' => '10', 'at' => '11/5(木) 22:05']])->get("/tasks/{$t->id}/edit")
            ->assertSee('夜間でもLINEに再通知しますか？')->assertSee('夜間でも予約する')->assertSee('name="night_ok" value="1"', false)
            // 「やめる」は # 付きの同じ URL にしない（同じページ内の移動になり、確認が消えない。ブラウザテストで見つけた不具合）
            ->assertSee('href="'.route('tasks.edit', $t).'" class="btn btn-small bg-panel-2" data-ask-close>やめる</a>', false);

        // 確認したら予約できる
        $this->post("/tasks/{$t->id}/reminders", ['when' => '10', 'night_ok' => '1'])->assertSessionHas('status', '11/5(木) 22:05 に再通知します（夜間）');
        $this->assertSame(1, TaskReminder::count());

        // 翌朝8時は夜間ではない
        $this->post("/tasks/{$t->id}/reminders", ['when' => 'tomorrow'])->assertSessionHas('status', '11/6(金) 08:00 に再通知します');
    }

    public function test_snooze_booking_is_limited_before_the_monthly_limit(): void
    {
        // LINE側の集計 195通・上限 200通、予約済みのスヌーズ 4件 → あと1件までは予約できる（195+4+1=200）
        $this->useLine(['api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 195])]);
        $t = $this->task('歯医者', '2026-11-05', '14:00');
        $other = $this->task('ほか', '2026-11-05', '15:00');
        foreach (['09:30', '09:40', '09:50', '10:30'] as $hm) {
            TaskReminder::query()->create(['task_id' => $other->id, 'remind_at' => "2026-11-05 {$hm}"]);
        }

        $this->post("/tasks/{$t->id}/reminders", ['when' => '10'])->assertSessionHas('status', '11/5(木) 09:10 に再通知します');
        $this->post("/tasks/{$t->id}/reminders", ['when' => '60'])
            ->assertSessionHas('snooze_blocked', fn ($s) => str_contains($s, '上限200通を超えるおそれがあります'));
        $this->assertSame(1, $t->reminders()->count());
        $this->get("/tasks/{$t->id}/edit")->assertSee('上限200通を超えるおそれがあります')->assertSee('disabled', false);

        // シミュレーションでは制限しない
        config(['line.driver' => 'log']);
        $this->post("/tasks/{$t->id}/reminders", ['when' => '60'])->assertSessionHas('status', '11/5(木) 10:00 に再通知します');
    }

    public function test_when_line_count_is_unknown_the_app_count_is_used_with_a_margin(): void
    {
        $this->useLine(['api.line.me/v2/bot/message/quota/consumption' => Http::response([], 500)]);
        $t = $this->task('歯医者', '2026-11-05', '14:00');
        $sent = fn (int $n) => collect(range(1, $n))->each(fn ($i) => NotificationLog::query()->create([
            'dedupe_key' => 'old:'.uniqid('', true), 'type' => 'before', 'scheduled_at' => '2026-11-02 10:00', 'status' => 'sent', 'sent_at' => '2026-11-02 10:00', 'message' => 'x']));
        $sent(179);

        $this->assertFalse(app(SendQuota::class)->officialAvailable());
        $this->assertSame([200, 180], [app(SendQuota::class)->limit(), app(SendQuota::class)->stopAt()]); // .env の上限と余裕
        $this->post("/tasks/{$t->id}/reminders", ['when' => '10'])->assertSessionHas('status', '11/5(木) 09:10 に再通知します'); // 179+0+1 = 180
        $this->post("/tasks/{$t->id}/reminders", ['when' => '60'])
            ->assertSessionHas('snooze_blocked', fn ($s) => str_contains($s, 'LINE側の送信数を確認できないため、余裕をもった目安180通'));
        $this->get('/settings')->assertSee('LINE側の送信数を確認できませんでした');
    }

    public function test_the_limit_follows_the_plan(): void
    {
        // 有料プランなど：LINE に上限の設定がない → 上限なし（再通知を止めない・予約も制限しない）
        $this->useLine([
            'api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 500]),
            'api.line.me/v2/bot/message/quota' => Http::response(['type' => 'none']),
        ]);
        $q = app(SendQuota::class);
        $this->assertSame([null, null, false, false], [$q->limit(), $q->stopAt(), $q->remindersStopped(), $q->limitReached()]);
        $this->assertNull($q->snoozeBlocked(CarbonImmutable::parse('2026-11-05 10:00')));
        $this->get('/settings')->assertSee('上限なし');
    }

    public function test_the_limit_from_env_is_used_when_line_cannot_tell(): void
    {
        // LINE から取れないときは .env の値（上限 1000通 → 980通で完了まで再通知を止める。0 なら上限なし）
        $this->useLine(['api.line.me/v2/bot/message/quota/consumption' => Http::response([], 500)]);
        config(['line.monthly_limit' => 1000]);
        $this->assertSame([1000, 980], [app(SendQuota::class)->limit(), app(SendQuota::class)->stopAt()]);
        config(['line.monthly_limit' => 0]);
        $this->assertSame([null, null], [app(SendQuota::class)->limit(), app(SendQuota::class)->stopAt()]);
    }

    public function test_snooze_is_not_sent_once_the_limit_is_reached_but_next_month_can_be_booked(): void
    {
        $this->useLine(['api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 200])]);
        $t = $this->task('歯医者', '2026-11-05', '14:00', ['notify_before_minutes' => 0, 'notify_morning' => false, 'notify_day_before' => false]);
        TaskReminder::query()->create(['task_id' => $t->id, 'remind_at' => '2026-11-05 09:10']);

        $this->run_('2026-11-05 09:10');
        $this->assertCount(0, $this->pushes());
        $this->assertStringContainsString('上限（200通）に達した', NotificationLog::query()->where('type', 'snooze')->value('last_error'));

        // 月末に「翌日」を予約 → 来月の分なので予約できる
        $this->travelTo(CarbonImmutable::parse('2026-11-30 20:00'));
        $this->post("/tasks/{$t->id}/reminders", ['when' => 'tomorrow'])->assertSessionHas('status', '12/1(火) 08:00 に再通知します');
    }

    // ===================== バックアップ =====================

    public function test_backup_v4_keeps_series_and_restoring_does_not_send_old_reminders(): void
    {
        $this->post('/tasks', $this->form(['title' => '週1', 'date' => '2026-11-09', 'repeat' => 'weekly', 'repeat_weekdays' => ['1'], 'repeat_end' => 'until', 'repeat_until' => '2026-12-31',
            'remind_until_done' => '1', 'subtasks' => [['title' => '床'], ['title' => 'ゴミ']]]));
        $skip = Task::query()->whereDate('date', '2026-11-16')->first();
        $this->delete("/tasks/{$skip->id}", ['scope' => 'this']);
        $mod = Task::query()->whereDate('date', '2026-11-23')->first();
        $this->put("/tasks/{$mod->id}", $this->form(['title' => '週1（特別）', 'date' => '2026-11-23', 'repeat' => 'weekly', 'repeat_weekdays' => ['1'], 'scope' => 'this']));
        $this->task('今日の書類', '2026-11-05', '07:00', ['remind_until_done' => true]); // 再通知 8:00 は戻す前に過ぎている
        TaskReminder::query()->create(['task_id' => Task::query()->where('title', '今日の書類')->value('id'), 'remind_at' => '2026-11-05 09:30']);

        $json = Backup::export();
        $this->assertSame(4, $json['version']);
        $this->assertSame(['weekly', [1], '2026-12-31', ['2026-11-16'], ['床', 'ゴミ'], true],
            [$json['series'][0]['freq'], $json['series'][0]['weekdays'], $json['series'][0]['until'], $json['series'][0]['skips'], $json['series'][0]['subtasks'], $json['series'][0]['remind_until_done']]);
        $this->assertStringNotContainsString('remind_at', json_encode($json)); // スヌーズの予約は書き出さない

        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:20'));
        Backup::restore($json);

        $s = TaskSeries::query()->sole();
        $this->assertSame([1], $s->weekdayList());
        $this->assertSame(['2026-11-09', '2026-11-23', '2026-11-30'], array_slice($this->dates($s->id), 0, 3));
        $this->assertTrue(Task::query()->where('title', '週1（特別）')->first()->series_modified);
        Recurrence::fill($s);
        $this->assertSame(0, Task::query()->whereDate('date', '2026-11-16')->count()); // 削除した回は戻らない

        $this->run_('2026-11-05 09:21'); // 戻す前の時刻の再通知（8:00, 9:00）は送らない
        $this->assertSame(0, NotificationLog::query()->whereIn('type', ['nag', 'snooze'])->count());

        // version 3 のファイルも読み込める（繰り返しなしの予定として）
        $v3 = $json;
        $v3['version'] = 3;
        unset($v3['series']);
        foreach ($v3['tasks'] as &$task) {
            unset($task['series_id'], $task['series_date'], $task['series_modified'], $task['remind_until_done']);
        }
        unset($task);
        $this->assertSame(count($json['tasks']), Backup::restore($v3));
        $this->assertSame(0, TaskSeries::count());
        $this->assertSame(0, Task::query()->whereNotNull('series_id')->count());
    }
}
