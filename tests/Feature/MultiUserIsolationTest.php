<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Invitation;
use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\TaskSeries;
use App\Models\User;
use App\Services\NotificationPlanner;
use App\Support\Owner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * ユーザーごとのデータの分離（最重要）
 * A と B がそれぞれのデータを持つ状態で、A からは B のデータを見る・変える・消すことが一切できないことを確かめる
 */
class MultiUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_A = 'token-of-user-a-0123456789abcdef';

    private const TOKEN_B = 'token-of-user-b-0123456789abcdef';

    private const LINE_A = 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const LINE_B = 'Ubbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private User $a;

    private User $b;

    /** B のデータ（A から触れないことを確かめる） */
    private Task $bTask;

    private Category $bCat;

    private Subtask $bSub;

    private TaskReminder $bReminder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
        Http::preventStrayRequests(); // 本物のLINEには送らない
        $this->a = User::factory()->create(['name' => 'A', 'email' => 'a@example.com']);
        $this->b = User::factory()->create(['name' => 'B', 'email' => 'b@example.com']);

        Owner::run($this->a, function () {
            Category::createDefaults();
            Task::create(['title' => 'Aの歯医者', 'date' => '2026-11-05', 'start_time' => '14:00', 'end_time' => '15:00',
                'category_id' => Category::query()->where('key', 'health')->value('id')]);
        });
        Owner::run($this->b, function () {
            Category::createDefaults();
            $this->bCat = Category::query()->where('key', 'work')->firstOrFail();
            $this->bTask = Task::create(['title' => 'Bの面談', 'date' => '2026-11-05', 'start_time' => '14:00', 'end_time' => '15:00', 'category_id' => $this->bCat->id]);
            $this->bSub = $this->bTask->subtasks()->create(['title' => 'Bの資料', 'done' => false, 'sort_order' => 0]);
            $this->bReminder = TaskReminder::query()->create(['task_id' => $this->bTask->id, 'remind_at' => '2026-11-05 12:00']);
            Setting::put('morning_time', '06:30');
        });
    }

    /** B のデータが変わっていないこと */
    private function assertBUntouched(): void
    {
        Owner::run($this->b, function () {
            $t = Task::query()->findOrFail($this->bTask->id);
            $this->assertSame('Bの面談', $t->title);
            $this->assertFalse($t->done);
            $this->assertFalse($t->gcal_added);
            $this->assertSame($this->b->id, $t->user_id);
            $this->assertSame($this->bCat->id, $t->category_id);
            $this->assertSame('仕事', Category::query()->findOrFail($this->bCat->id)->name);
            $this->assertSame(6, Category::count());
            $this->assertFalse(Subtask::query()->findOrFail($this->bSub->id)->done);
            $this->assertSame(1, TaskReminder::count());
            $this->assertSame('06:30', Setting::values()['morning_time']);
        });
    }

    // 8) A と B がそれぞれログインできる（同時にログインしていても、見えるのは自分のデータだけ）
    public function test_a_and_b_can_each_log_in_and_see_only_their_own_calendar(): void
    {
        foreach (['a@example.com' => ['Aの歯医者', 'Bの面談'], 'b@example.com' => ['Bの面談', 'Aの歯医者']] as $email => [$mine, $theirs]) {
            $this->post('/login', ['email' => $email, 'password' => 'password'])->assertRedirect('/');
            $this->get('/?view=month&date=2026-11-05')->assertOk()->assertSee($mine)->assertDontSee($theirs);
            $this->get('/?view=week&date=2026-11-05')->assertOk()->assertSee($mine)->assertDontSee($theirs);
            $this->get('/?view=day&date=2026-11-05')->assertOk()->assertSee($mine)->assertDontSee($theirs);
            $this->get('/?view=list&date=2026-11-05')->assertOk()->assertSee($mine)->assertDontSee($theirs);
            $this->post('/logout');
        }
    }

    public function test_two_sessions_at_the_same_time_stay_separate(): void
    {
        // A のセッションと B のセッションを交互に使う（同時ログイン）
        $this->actingAs($this->a)->get('/?view=day&date=2026-11-05')->assertSee('Aの歯医者')->assertDontSee('Bの面談');
        $this->actingAs($this->b)->get('/?view=day&date=2026-11-05')->assertSee('Bの面談')->assertDontSee('Aの歯医者');
        $this->actingAs($this->a)->post('/tasks', $this->taskForm(['title' => 'Aの新しい予定']))->assertRedirect();
        $this->actingAs($this->b)->get('/?view=day&date=2026-11-06')->assertDontSee('Aの新しい予定');
        $this->assertSame($this->a->id, Owner::run($this->a, fn () => Task::query()->where('title', 'Aの新しい予定')->value('user_id')));
    }

    // 9) A は B の予定を見られない
    public function test_a_cannot_view_bs_task(): void
    {
        $this->actingAs($this->a);
        $this->get("/tasks/{$this->bTask->id}/edit")->assertNotFound()->assertDontSee('Bの面談');
        $this->get('/export/ics?ids='.$this->bTask->id)->assertOk()->assertDontSee('Bの面談');
        $this->get('/export/ics')->assertOk()->assertDontSee('Bの面談')->assertSee('Aの歯医者');
        $this->get('/import/done?ids='.$this->bTask->id)->assertOk()->assertDontSee('Bの面談');
        $this->withSession(['gcal_task' => $this->bTask->id])->get('/')->assertOk()->assertDontSee('Bの面談');
        $json = $this->get('/export/json')->assertOk()->getContent();
        $this->assertStringNotContainsString('Bの面談', $json);
        $this->assertStringNotContainsString('Bの資料', $json);
        $this->get('/settings')->assertOk()->assertDontSee('Bの面談');
    }

    // 10) A は B の予定を編集できない（完了・Googleカレンダー・スヌーズ・チェック項目も）
    public function test_a_cannot_edit_bs_task(): void
    {
        $this->actingAs($this->a);
        $id = $this->bTask->id;
        $this->put("/tasks/{$id}", $this->taskForm(['title' => '書き換え']))->assertNotFound();
        $this->patch("/tasks/{$id}", $this->taskForm(['title' => '書き換え']))->assertNotFound();
        $this->patch("/tasks/{$id}/done", ['done' => 1])->assertNotFound();
        $this->patchJson("/tasks/{$id}/done", ['done' => 1])->assertNotFound();
        $this->post("/tasks/{$id}/google")->assertNotFound();
        $this->post("/tasks/{$id}/reminders", ['when' => '10'])->assertNotFound();
        $this->patch("/subtasks/{$this->bSub->id}/done", ['done' => 1])->assertNotFound();
        // 期限切れのまとめて完了も、自分の予定だけ
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
        $count = Owner::run($this->a, fn () => Task::query()->where('done', false)->where('date', '<', '2026-11-06')->count());
        $this->post('/overdue/complete', ['range' => 'all', 'count' => $count])->assertRedirect();

        $this->assertBUntouched();
    }

    // 11) A は B の予定を削除できない
    public function test_a_cannot_delete_bs_data(): void
    {
        $this->actingAs($this->a);
        $this->delete("/tasks/{$this->bTask->id}")->assertNotFound();
        $this->delete("/tasks/{$this->bTask->id}", ['scope' => 'following'])->assertNotFound();
        $this->delete("/reminders/{$this->bReminder->id}")->assertNotFound();
        $this->delete("/categories/{$this->bCat->id}")->assertNotFound();
        // 「予定などのデータを削除」も、バックアップから戻すのも、A のデータだけ
        $this->delete('/data')->assertRedirect('/');
        $file = UploadedFile::fake()->createWithContent('b.json', json_encode(['app' => 'irodori-techo', 'version' => 4, 'tasks' => [['title' => '戻した予定', 'date' => '2026-11-07']]]));
        $this->post('/restore', ['backup' => $file])->assertRedirect('/');

        $this->assertBUntouched();
        $this->assertSame(1, Owner::run($this->b, fn () => Task::count()));
        $this->assertSame(['戻した予定'], Owner::run($this->a, fn () => Task::query()->pluck('title')->all()));
    }

    // フォームで user_id などを送っても、ほかの人のデータは作れない・持ち主は変えられない
    public function test_sending_user_id_or_other_peoples_ids_in_forms_does_nothing(): void
    {
        $this->actingAs($this->a);
        $this->post('/tasks', $this->taskForm(['title' => 'なりすまし', 'user_id' => $this->b->id]))->assertRedirect();
        $this->assertSame($this->a->id, Owner::run($this->a, fn () => Task::query()->where('title', 'なりすまし')->value('user_id')));
        $this->assertSame(0, Owner::run($this->b, fn () => Task::query()->where('title', 'なりすまし')->count()));

        // B のカテゴリは選べない
        $this->post('/tasks', $this->taskForm(['title' => '他人の色', 'category_id' => $this->bCat->id]))->assertSessionHasErrors('category_id');

        // 自分の予定に B のチェック項目の ID を送っても、B のチェック項目は変わらない（新しく作る）
        $mine = Owner::run($this->a, fn () => Task::query()->where('title', 'なりすまし')->firstOrFail());
        $this->put("/tasks/{$mine->id}", $this->taskForm(['title' => 'なりすまし', 'user_id' => $this->b->id,
            'subtasks' => [['id' => $this->bSub->id, 'title' => '乗っ取り', 'done' => '1']]]))->assertRedirect();
        $this->assertSame($this->a->id, Owner::run($this->a, fn () => $mine->refresh()->user_id));
        $this->assertSame('Bの資料', Owner::run($this->b, fn () => Subtask::query()->findOrFail($this->bSub->id)->title));

        // 凡例のまとめて保存に B のカテゴリの ID を混ぜても、B のカテゴリは変わらない
        $this->put('/categories', ['categories' => [$this->bCat->id => ['name' => '乗っ取り', 'color' => '#000000']]])->assertRedirect();

        // LINE連携に user_id を送っても、持ち主は自分
        $this->put('/settings/line', ['channel_access_token' => self::TOKEN_A, 'line_user_id' => self::LINE_A, 'user_id' => $this->b->id]);
        $this->assertSame($this->a->id, Owner::run($this->a, fn () => LineConnection::current()->user_id));
        $this->assertNull(Owner::run($this->b, fn () => LineConnection::current()));

        $this->assertBUntouched();
    }

    public function test_models_refuse_to_change_or_borrow_another_owner(): void
    {
        $this->expectException(LogicException::class);
        Owner::run($this->a, fn () => Task::query()->firstOrFail()->forceFill(['user_id' => $this->b->id])->save());
    }

    public function test_children_cannot_be_attached_to_another_users_task(): void
    {
        $this->expectException(LogicException::class);
        Owner::run($this->a, fn () => TaskReminder::query()->create(['task_id' => $this->bTask->id, 'remind_at' => '2026-11-05 13:00']));
    }

    public function test_without_an_owner_nothing_is_readable(): void
    {
        // ログインしていない・Owner::run の外では、持ち主のあるデータは読めない（全員分を返さない）
        $this->expectException(LogicException::class);
        Task::query()->count();
    }

    // 12) A と B のカテゴリが混ざらない
    public function test_categories_do_not_mix(): void
    {
        $this->actingAs($this->a);
        $this->put('/categories', ['categories' => Owner::run($this->a, fn () => Category::query()->pluck('id'))
            ->mapWithKeys(fn ($id, $i) => [$id => ['name' => "A{$i}", 'color' => '#A3C1DE']])->all()])->assertRedirect();
        $this->post('/categories')->assertRedirect();
        $aCats = Owner::run($this->a, fn () => Category::ordered()->pluck('name')->all());
        $this->assertSame(['A0', 'A1', 'A2', 'A3', 'A4', 'A5', '新しいカテゴリ'], $aCats);
        $this->assertSame(array_column(Category::DEFAULTS, 'name'), Owner::run($this->b, fn () => Category::ordered()->pluck('name')->all()));
        // A がカテゴリを消して予定が移っても、B の予定は動かない
        $first = Owner::run($this->a, fn () => Category::query()->where('key', 'health')->firstOrFail());
        $this->delete("/categories/{$first->id}")->assertRedirect();
        $this->get('/settings')->assertOk()->assertDontSee('仕事'); // B の凡例名（A は名前を変えた）
        $this->assertBUntouched();
    }

    // 13) A と B の繰り返し予定が混ざらない
    public function test_recurring_tasks_do_not_mix(): void
    {
        $this->actingAs($this->a)->post('/tasks', $this->taskForm(['title' => 'Aの朝ラン', 'repeat' => 'daily']))->assertRedirect();
        $this->actingAs($this->b)->post('/tasks', $this->taskForm(['title' => 'Bの週報', 'repeat' => 'weekly', 'repeat_weekdays' => [5]]))->assertRedirect();

        $aSeries = Owner::run($this->a, fn () => TaskSeries::query()->sole());
        $bSeries = Owner::run($this->b, fn () => TaskSeries::query()->sole());
        $this->assertSame($this->a->id, $aSeries->user_id);
        $this->assertSame($this->b->id, $bSeries->user_id);
        // A からは B の繰り返しが見えない
        $this->assertNull(Owner::run($this->a, fn () => TaskSeries::query()->find($bSeries->id)));

        // 毎日の自動補充（全員分）：作った回は、それぞれの繰り返しの持ち主の予定
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:10'));
        $this->artisan('tasks:fill-recurring')->assertSuccessful();
        $aTitles = Owner::run($this->a, fn () => Task::query()->whereNotNull('series_id')->distinct()->pluck('title')->all());
        $bTitles = Owner::run($this->b, fn () => Task::query()->whereNotNull('series_id')->distinct()->pluck('title')->all());
        $this->assertSame(['Aの朝ラン'], $aTitles);
        $this->assertSame(['Bの週報'], $bTitles);
        $this->assertSame(0, DB::table('tasks')->whereNotNull('series_id')->where('series_id', $aSeries->id)->where('user_id', '!=', $this->a->id)->count());
        $this->assertSame(0, DB::table('tasks')->whereNotNull('series_id')->where('series_id', $bSeries->id)->where('user_id', '!=', $this->b->id)->count());

        // A が「この予定以後すべて削除」しても、B の繰り返しは残る
        $aOcc = Owner::run($this->a, fn () => Task::query()->where('series_id', $aSeries->id)->orderBy('date')->firstOrFail());
        $this->actingAs($this->a)->delete("/tasks/{$aOcc->id}", ['scope' => 'following'])->assertRedirect();
        $this->assertGreaterThan(0, Owner::run($this->b, fn () => Task::query()->where('series_id', $bSeries->id)->count()));
        // A は B の回を「この予定だけ」削除できない
        $bOcc = Owner::run($this->b, fn () => Task::query()->where('series_id', $bSeries->id)->firstOrFail());
        $this->actingAs($this->a)->delete("/tasks/{$bOcc->id}")->assertNotFound();
        $this->assertTrue(Owner::run($this->b, fn () => Task::query()->whereKey($bOcc->id)->exists()));
    }

    // 14) A と B の通知が混ざらない（同じ時刻の「当日朝」も人ごとに別の通知・別の本文・二重送信の防止も人ごと）
    public function test_notifications_do_not_mix(): void
    {
        Owner::run($this->b, fn () => Setting::put('morning_time', '08:00')); // 同じ時刻にそろえる
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00'));
        $this->artisan('notifications:send')->assertSuccessful();
        $this->artisan('notifications:send')->assertSuccessful(); // 2回動いても二重に送らない

        $logs = DB::table('notification_logs')->where('dedupe_key', 'morning:2026-11-05')->orderBy('user_id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$this->a->id, $this->b->id], $logs->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertStringContainsString('Aの歯医者', $logs[0]->message);
        $this->assertStringNotContainsString('Bの面談', $logs[0]->message);
        $this->assertStringContainsString('Bの面談', $logs[1]->message);
        $this->assertStringNotContainsString('Aの歯医者', $logs[1]->message);

        // 画面の通知履歴・これから届く通知も、自分の分だけ
        $this->actingAs($this->a)->get('/settings')->assertOk()->assertDontSee('Bの面談');
        $upcoming = Owner::run($this->a, fn () => (new NotificationPlanner(Setting::values()))->upcoming(7));
        $this->assertNotEmpty($upcoming);
        $this->assertStringNotContainsString('Bの', json_encode($upcoming, JSON_UNESCAPED_UNICODE));
        // B のスヌーズ（12:00）は B にだけ届く
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00'));
        $this->artisan('notifications:send')->assertSuccessful();
        $this->assertSame(0, Owner::run($this->a, fn () => NotificationLog::query()->where('type', 'snooze')->count()));
        $this->assertSame(1, Owner::run($this->b, fn () => NotificationLog::query()->where('type', 'snooze')->count()));
    }

    public function test_line_pushes_go_to_each_users_own_line_only(): void
    {
        config(['line.driver' => 'line']);
        Owner::run($this->a, fn () => LineConnection::query()->create(['channel_access_token' => self::TOKEN_A, 'line_user_id' => self::LINE_A]));
        Owner::run($this->b, fn () => LineConnection::query()->create(['channel_access_token' => self::TOKEN_B, 'line_user_id' => self::LINE_B]));
        Http::fake(['api.line.me/*' => Http::response([])]);

        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00')); // 14:00 の60分前
        $this->artisan('notifications:send')->assertSuccessful();

        $pushes = collect(Http::recorded())->map(fn ($r) => $r[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), '/message/push'));
        $this->assertCount(2, $pushes);
        foreach ($pushes as $p) {
            $to = $p->data()['to'];
            $text = $p->data()['messages'][0]['text'];
            if ($to === self::LINE_A) {
                $this->assertTrue($p->hasHeader('Authorization', 'Bearer '.self::TOKEN_A));
                $this->assertStringContainsString('Aの歯医者', $text);
                $this->assertStringNotContainsString('Bの', $text);
            } else {
                $this->assertSame(self::LINE_B, $to);
                $this->assertTrue($p->hasHeader('Authorization', 'Bearer '.self::TOKEN_B));
                $this->assertStringContainsString('Bの面談', $text);
                $this->assertStringNotContainsString('Aの', $text);
            }
        }
    }

    public function test_user_without_line_does_not_block_others(): void
    {
        config(['line.driver' => 'line']);
        Owner::run($this->a, fn () => LineConnection::query()->create(['channel_access_token' => self::TOKEN_A, 'line_user_id' => self::LINE_A]));
        Http::fake(['api.line.me/*' => Http::response([])]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00'));

        $this->artisan('notifications:send')->expectsOutputToContain('LINEを連携していない 1 人')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(0, Owner::run($this->b, fn () => NotificationLog::count())); // B には何も記録しない（あとで連携したら、それ以降から送る）
    }

    // 15) A と B の LINE設定が混ざらない
    public function test_line_settings_do_not_mix(): void
    {
        $this->actingAs($this->a)->put('/settings/line', ['channel_access_token' => self::TOKEN_A, 'line_user_id' => self::LINE_A])->assertRedirect();
        $this->actingAs($this->b)->get('/settings/line')->assertOk()->assertSee('未連携')->assertDontSee('••••'.substr(self::TOKEN_A, -4))->assertDontSee('Uaa••••');
        $this->actingAs($this->b)->post('/settings/line/test')->assertSessionHas('status', '先にトークンとユーザーIDを保存してください');
        $this->actingAs($this->b)->delete('/settings/line')->assertRedirect();
        $this->assertSame(self::LINE_A, Owner::run($this->a, fn () => LineConnection::current()->line_user_id));

        $this->actingAs($this->b)->put('/settings/line', ['channel_access_token' => self::TOKEN_B, 'line_user_id' => self::LINE_B])->assertRedirect();
        $this->assertSame(self::TOKEN_A, Owner::run($this->a, fn () => LineConnection::current()->channel_access_token));
        $this->assertSame(self::TOKEN_B, Owner::run($this->b, fn () => LineConnection::current()->channel_access_token));
        $this->assertSame(2, DB::table('line_connections')->count());
        // テスト送信の履歴も、自分の分だけ
        $this->actingAs($this->a)->post('/settings/line/test')->assertRedirect();
        $this->assertSame(1, Owner::run($this->a, fn () => NotificationLog::query()->where('type', 'test')->count()));
        $this->assertSame(0, Owner::run($this->b, fn () => NotificationLog::query()->where('type', 'test')->count()));
    }

    public function test_settings_do_not_mix(): void
    {
        $this->actingAs($this->a)->put('/settings', ['day_before_time' => '21:00', 'morning_time' => '07:15', 'default_before' => 30])->assertRedirect();
        $this->actingAs($this->a)->put('/settings/display', ['month_show_titles' => '1'])->assertRedirect();
        $this->assertSame('07:15', Owner::run($this->a, fn () => Setting::values()['morning_time']));
        $this->assertSame(1, Owner::run($this->a, fn () => Setting::values()['month_show_titles']));
        $this->assertSame(0, Owner::run($this->b, fn () => Setting::values()['month_show_titles']));
        $this->assertSame('23:00', Owner::run($this->b, fn () => Setting::values()['day_before_time']));
        $this->assertBUntouched();
    }

    // 16) 招待で新しい人が登録しても、前からいる人のデータはそのまま
    public function test_registering_a_new_user_keeps_existing_users_data(): void
    {
        $count = fn (User $u) => Owner::run($u, fn () => [Task::count(), Category::count(), Subtask::count(), TaskReminder::count(), Setting::count()]);
        $beforeA = $count($this->a);
        $beforeB = $count($this->b);

        $token = Invitation::newToken();
        (new Invitation)->forceFill(['email' => 'c@example.com', 'token_hash' => Invitation::hashToken($token), 'expires_at' => now()->addDays(7)])->save();
        $this->post('/invite', ['token' => $token])->assertRedirect('/register');
        $this->post('/register', ['name' => 'C', 'email' => 'c@example.com', 'password' => 'password-of-c-123', 'password_confirmation' => 'password-of-c-123'])->assertRedirect('/');
        $c = User::query()->where('email', 'c@example.com')->sole();

        $this->get('/?view=list&date=2026-11-05')->assertOk()->assertDontSee('Aの歯医者')->assertDontSee('Bの面談');
        $this->assertSame([0, 6, 0, 0, 0], $count($c));
        $this->assertSame($beforeA, $count($this->a));
        $this->assertSame($beforeB, $count($this->b));
        $this->assertSame(3, User::count());
    }

    // 19) ログインしていなければ、予定にもどの画面にも入れない
    public function test_guests_cannot_reach_any_data(): void
    {
        $id = $this->bTask->id;
        foreach (['/', "/tasks/{$id}/edit", '/tasks/create', '/settings', '/settings/line', '/settings/password', '/export/ics', '/export/json', '/import', '/overdue/complete'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->put("/tasks/{$id}", $this->taskForm())->assertRedirect('/login');
        $this->delete("/tasks/{$id}")->assertRedirect('/login');
        $this->patchJson("/tasks/{$id}/done", ['done' => 1])->assertUnauthorized();
        $this->patch("/subtasks/{$this->bSub->id}/done", ['done' => 1])->assertRedirect('/login');
        $this->delete("/reminders/{$this->bReminder->id}")->assertRedirect('/login');
        $this->put('/settings/password', [])->assertRedirect('/login');
        $this->delete('/data')->assertRedirect('/login');
        $this->assertBUntouched();
    }

    // 20) 既存の予定の機能が、2人目のユーザーでも普通に動く
    public function test_existing_task_features_work_for_each_user(): void
    {
        $this->actingAs($this->b);
        $this->post('/tasks', $this->taskForm(['title' => 'Bの買い物', 'priority' => 1, 'subtasks' => [['title' => '牛乳'], ['title' => 'パン']]]))->assertRedirect();
        $t = Owner::run($this->b, fn () => Task::query()->where('title', 'Bの買い物')->firstOrFail());
        $this->get("/tasks/{$t->id}/edit")->assertOk()->assertSee('牛乳');
        $this->patchJson("/tasks/{$t->id}/done", ['done' => 1])->assertOk()->assertJson(['done' => true]);
        $sub = Owner::run($this->b, fn () => $t->subtasks()->firstOrFail());
        $this->patchJson("/subtasks/{$sub->id}/done", ['done' => 1])->assertOk();
        $this->patchJson("/tasks/{$t->id}/done", ['done' => 0])->assertOk();
        $this->post("/tasks/{$t->id}/reminders", ['when' => '60'])->assertRedirect();
        $this->put("/tasks/{$t->id}", $this->taskForm(['title' => 'Bの買い物（変更）']))->assertRedirect();
        $this->get('/?view=month&date=2026-11-06')->assertOk()->assertSee('今日やること');
        $this->get('/?view=list&date=2026-11-06')->assertOk()->assertSee('Bの買い物（変更）');
        $this->delete("/tasks/{$t->id}")->assertRedirect();
        $this->assertFalse(Owner::run($this->b, fn () => Task::query()->whereKey($t->id)->exists()));
        $this->assertBUntouched();
    }

    private function taskForm(array $over = []): array
    {
        return [
            'title' => '予定', 'date' => '2026-11-06', 'start_time' => '10:00', 'end_time' => '11:00',
            'notify_day_before' => '1', 'notify_morning' => '1', 'notify_before_minutes' => 60, 'priority' => 2,
            ...$over,
        ];
    }
}
