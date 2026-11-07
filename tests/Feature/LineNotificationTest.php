<?php

namespace Tests\Feature;

use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\Backup;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LINE通知の送信（実際のLINEには送らない。LINE_DRIVER=line のテストは Http::fake() で受ける）
 * 基本の予定：11/5(木) 14:00 歯医者（前日 23:00・当日 8:00・60分前 13:00）
 */
class LineNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-channel-access-token-0123456789abcdef';

    private const USER_ID = 'U0123456789abcdef0123456789abcdef';

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00'));
        Http::preventStrayRequests(); // 本物のLINEには絶対に送らない
        $this->actingAs(User::factory()->create()); // データはユーザーごとなので、先にログインする
        $this->task = $this->task('歯医者', '2026-11-05', '14:00');
    }

    private function task(string $title, string $date, ?string $start, array $extra = []): Task
    {
        return Task::create(Task::normalizeTimes([
            'title' => $title, 'date' => $date, 'all_day' => $start === null, 'start_time' => $start,
            'notify_day_before' => true, 'notify_morning' => true, 'notify_before_minutes' => 60, ...$extra,
        ]));
    }

    private function run_(string $at): array
    {
        $this->travelTo(CarbonImmutable::parse($at));

        return app(NotificationDispatcher::class)->run();
    }

    private function useLine(): void
    {
        config(['line.driver' => 'line']);
        LineConnection::query()->create(['channel_access_token' => self::TOKEN, 'line_user_id' => self::USER_ID]);
    }

    /** 基本の予定の「開始前」の通知（13:00 に動かすと、8:00 の当日朝は「見送り」として別に記録される） */
    private function beforeLog(): NotificationLog
    {
        return NotificationLog::query()->where('type', 'before')->sole();
    }

    private function pushes(): array
    {
        return Http::recorded(fn (Request $r) => $r->url() === 'https://api.line.me/v2/bot/message/push')->all();
    }

    public function test_planner_builds_three_notifications_in_japan_time(): void
    {
        $this->task('打ち合わせ', '2026-11-05', '10:00', ['notify_before_minutes' => 15]);
        $this->task('資料の提出', '2026-11-05', null);                                   // 終日 → 開始前はない
        $this->task('済んだ予定', '2026-11-05', '09:00', ['done' => true]);              // 完了 → 通知しない
        $this->task('通知なし', '2026-11-05', '11:00', ['notify_day_before' => false, 'notify_morning' => false, 'notify_before_minutes' => 0]);

        $items = (new NotificationPlanner(Setting::values()))->between(CarbonImmutable::parse('2026-11-04 00:00'), CarbonImmutable::parse('2026-11-05 23:59'));
        $got = array_map(fn ($i) => [$i['at']->format('m/d H:i'), $i['type']], $items);

        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $this->assertSame([
            ['11/04 23:00', 'day_before'],
            ['11/05 08:00', 'morning'],
            ['11/05 09:45', 'before'],
            ['11/05 13:00', 'before'],
        ], $got);
        // まとめは 終日 → 時刻の早い順。通知をオフにした予定・完了した予定は入らない
        $this->assertSame(['終日 資料の提出', '10:00 打ち合わせ', '14:00 歯医者'], $items[0]['lines']);
    }

    public function test_log_driver_simulates_without_sending_to_line(): void
    {
        Log::spy();
        $this->run_('2026-11-04 23:00');
        $this->run_('2026-11-04 23:01'); // 同じ通知は二度目は扱わない

        $log = NotificationLog::query()->sole();
        $this->assertSame('day_before:2026-11-05', $log->dedupe_key);
        $this->assertSame('simulated', $log->status);
        $this->assertStringContainsString('14:00 歯医者', $log->message);
        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($m, $ctx) => str_contains($m, 'シミュレーション') && ! str_contains(json_encode($ctx), self::TOKEN));
    }

    public function test_line_driver_sends_push_with_retry_key_from_the_first_request(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::response(['sentMessages' => [['id' => '1', 'quoteToken' => 'q']]])]);

        $this->run_('2026-11-05 08:00');

        $this->assertCount(1, $this->pushes());
        [$req] = $this->pushes()[0];
        $this->assertSame('Bearer '.self::TOKEN, $req->header('Authorization')[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $req->header('X-Line-Retry-Key')[0]);
        $this->assertSame(self::USER_ID, $req['to']);
        $this->assertSame("☀️ 今日の予定（11月5日(木)）\n・14:00 歯医者", $req['messages'][0]['text']);

        $log = NotificationLog::query()->sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame($log->retry_key, $req->header('X-Line-Retry-Key')[0]);
    }

    public function test_changed_deleted_or_turned_off_tasks_do_not_send_old_notifications(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::response([])]);
        $off = $this->task('オフにする', '2026-11-05', '13:30');
        $gone = $this->task('消す', '2026-11-05', '13:30');

        $this->task->update(['start_time' => '16:00', 'end_time' => '17:00']); // 14:00 → 16:00
        $off->update(['notify_before_minutes' => 0]);
        $gone->delete();

        $this->run_('2026-11-05 12:30'); // 元の 13:30 予定の60分前
        $this->run_('2026-11-05 13:00'); // 元の 14:00 の60分前
        $this->assertCount(0, $this->pushes());

        $this->run_('2026-11-05 15:00'); // 新しい 16:00 の60分前
        $this->assertCount(1, $this->pushes());
        $this->assertStringContainsString('16:00〜 歯医者', $this->pushes()[0][0]['messages'][0]['text']);
    }

    public function test_overdue_notifications_are_skipped_and_late_ones_show_real_time_left(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::response([])]);

        // サーバーが 8:00 に止まっていて 9:00 に動き出した → 当日朝の通知は見送り
        $this->run_('2026-11-05 09:00');
        $this->assertSame('skipped', NotificationLog::query()->where('dedupe_key', 'morning:2026-11-05')->value('status'));
        $this->assertCount(0, $this->pushes());

        // 13:00 の通知が 13:20 に送られる → 残り時間は40分と書く
        $this->run_('2026-11-05 13:20');
        $this->assertCount(1, $this->pushes());
        $this->assertSame("⏰ 40分後に始まります\n・14:00〜 歯医者", $this->pushes()[0][0]['messages'][0]['text']);
    }

    public function test_past_tasks_and_already_started_tasks_are_not_sent(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::response([])]);
        // 13:55 に 14:00 開始の予定を作った（60分前の 13:00 は過ぎている。猶予30分も過ぎている）
        $this->run_('2026-11-05 13:55');
        $this->assertCount(0, $this->pushes());
        $this->assertSame('skipped', NotificationLog::query()->where('type', 'before')->value('status'));

        // 過去の日の予定は計算の対象にもならない
        $this->task('きのう', '2026-11-04', '10:00');
        $this->run_('2026-11-05 13:56');
        $this->assertCount(0, $this->pushes());
    }

    public function test_server_error_is_retried_with_the_same_key_and_the_same_body(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::sequence()->push(['message' => 'error'], 500)->push([])]);

        $this->run_('2026-11-05 13:00');
        $log = $this->beforeLog();
        $this->assertSame('failed', $log->status);
        $this->assertSame('2026-11-05 13:01', $log->next_retry_at->format('Y-m-d H:i'));

        $this->run_('2026-11-05 13:00:30'); // まだ送り直す時刻ではない
        $this->assertCount(1, $this->pushes());

        $this->run_('2026-11-05 13:01');
        $this->assertCount(2, $this->pushes());
        [[$a], [$b]] = $this->pushes();
        $this->assertSame($a->header('X-Line-Retry-Key'), $b->header('X-Line-Retry-Key'));
        $this->assertSame($a->body(), $b->body());
        $this->assertSame('sent', $log->refresh()->status);
        $this->assertSame(2, $log->attempts);
    }

    public function test_connection_error_is_retried_and_409_counts_as_sent(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::sequence()->pushFailedConnection()->push(['message' => 'The retry key is already accepted'], 409)]);

        $this->run_('2026-11-05 13:00');
        $this->assertSame('failed', $this->beforeLog()->status);
        $this->run_('2026-11-05 13:01');
        $this->assertSame('sent', $this->beforeLog()->status);
        $this->assertCount(2, $this->pushes());
    }

    public function test_client_errors_are_not_retried(): void
    {
        $this->useLine();
        $this->task('もう一件', '2026-11-05', '15:00');
        Http::fake(['api.line.me/*' => Http::sequence()
            ->push(['message' => 'You have reached your monthly limit.'], 429)
            ->push(['message' => 'Authentication failed'], 401)]);

        $this->run_('2026-11-05 13:00');
        $this->run_('2026-11-05 14:00');
        $this->run_('2026-11-05 14:30'); // 送り直しはしない

        $this->assertCount(2, $this->pushes());
        $logs = NotificationLog::query()->where('type', 'before')->orderBy('scheduled_at')->get();
        $this->assertSame(['failed', 'failed'], $logs->pluck('status')->all());
        $this->assertSame([null, null], $logs->pluck('next_retry_at')->all());
        $this->assertStringContainsString('429', $logs[0]->last_error);
        $this->assertSame('error', LineConnection::current()->status); // 401 → 連携の再設定が必要
    }

    public function test_gives_up_after_max_attempts(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::response([], 500)]);

        foreach (['13:00', '13:01', '13:03', '13:07', '13:15'] as $t) {
            $this->run_("2026-11-05 {$t}");
        }

        $this->assertCount(4, $this->pushes());
        $log = $this->beforeLog();
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->next_retry_at);
    }

    public function test_retry_is_canceled_when_the_task_was_deleted(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::sequence()->push([], 500)->push([])]);

        $this->run_('2026-11-05 13:00');
        $this->task->delete();
        $this->run_('2026-11-05 13:01');

        $this->assertCount(1, $this->pushes());
        $this->assertSame('canceled', $this->beforeLog()->status);
    }

    public function test_retry_with_changed_content_uses_a_new_key(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::sequence()->push([], 500)->push([])]);

        $this->run_('2026-11-05 08:00');
        $this->task('追加した予定', '2026-11-05', '18:00');
        $this->run_('2026-11-05 08:01');

        $this->assertCount(2, $this->pushes());
        [[$a], [$b]] = $this->pushes();
        $this->assertNotSame($a->header('X-Line-Retry-Key'), $b->header('X-Line-Retry-Key'));
        $this->assertStringContainsString('18:00 追加した予定', $b['messages'][0]['text']);
    }

    public function test_stuck_pending_notification_is_resent_with_the_same_key(): void
    {
        $this->useLine();
        Http::fake(['api.line.me/*' => Http::response([])]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00'));
        $item = collect((new NotificationPlanner(Setting::values()))->between(now()->toImmutable()->subMinute(), now()->toImmutable()))->firstWhere('type', 'before');
        $log = NotificationLog::query()->create([
            'dedupe_key' => $item['key'], 'type' => 'before', 'task_id' => $this->task->id, 'scheduled_at' => $item['at'],
            'status' => 'pending', 'attempts' => 1, 'retry_key' => '123e4567-e89b-12d3-a456-426614174000',
            'signature' => $item['signature'], 'message' => NotificationPlanner::text($item, $item['at']),
        ]);

        $this->run_('2026-11-05 13:03'); // まだ5分たっていない
        $this->assertCount(0, $this->pushes());
        $this->run_('2026-11-05 13:06');

        $this->assertCount(1, $this->pushes());
        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $this->pushes()[0][0]->header('X-Line-Retry-Key')[0]);
        $this->assertSame('sent', $log->refresh()->status);
    }

    public function test_line_driver_without_connection_does_nothing(): void
    {
        config(['line.driver' => 'line']);
        Http::fake();

        $res = $this->run_('2026-11-05 13:00');

        $this->assertSame('not_connected', $res['state']);
        $this->assertSame(0, NotificationLog::count());
        Http::assertNothingSent();
    }

    public function test_restoring_a_backup_does_not_resend_finished_notifications(): void
    {
        $this->run_('2026-11-05 13:00'); // シミュレーションで送信済み
        $backup = Backup::export();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:10'));
        Backup::restore($backup); // 予定のIDが変わる
        $this->run_('2026-11-05 13:10');

        $this->assertSame(1, NotificationLog::query()->where('type', 'before')->count());
    }

    public function test_reset_keeps_login_and_line_connection(): void
    {
        $this->useLine();
        $user = auth()->user(); // 予定と LINE連携の持ち主
        Http::fake(['api.line.me/*' => Http::response([])]);
        $this->run_('2026-11-05 13:00');

        $this->actingAs($user)->delete('/data')->assertRedirect('/');

        $this->assertSame(0, Task::count());
        $this->assertSame(0, NotificationLog::count());
        $this->assertSame(1, User::count());
        $this->assertSame(self::TOKEN, LineConnection::current()->channel_access_token);
        $this->run_('2026-11-05 13:01');
        $this->assertCount(1, $this->pushes());
    }

    public function test_dry_run_command_records_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00'));

        $this->artisan('notifications:send --dry-run')->expectsOutputToContain('確認のみ')->assertSuccessful();
        $this->assertSame(0, NotificationLog::count());

        $this->artisan('notifications:send')->assertSuccessful();
        $this->assertSame('simulated', $this->beforeLog()->status);
    }

    public function test_scheduler_runs_the_command_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('notifications:send')->assertSuccessful();
    }
}
