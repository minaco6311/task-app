<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Backup;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use App\Services\TaskTextParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 第2段階：優先度（⑥）・サブタスク（⑨）・達成状況（⑩）・クイック登録（⑦）
 * 今日 = 2026-11-05(木) 9:00
 */
class Stage2Test extends TestCase
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

    private function form(array $extra = []): array
    {
        return [
            'title' => '旅行の準備', 'date' => '2026-11-06', 'start_time' => '10:00', 'end_time' => '11:00',
            'category_id' => Category::query()->where('key', 'private')->value('id'),
            'notify_day_before' => '1', 'notify_morning' => '1', 'notify_before_minutes' => 60, ...$extra,
        ];
    }

    // ===== ⑥ 優先度 =====

    public function test_priority_is_saved_and_defaults_to_middle(): void
    {
        $this->post('/tasks', $this->form(['title' => '大事な予定', 'priority' => 1]))->assertRedirect();
        $this->assertSame(1, Task::query()->where('title', '大事な予定')->value('priority'));

        $this->post('/tasks', $this->form(['title' => '指定なし']))->assertRedirect();
        $this->assertSame(2, Task::query()->where('title', '指定なし')->value('priority'));

        $this->post('/tasks', $this->form(['priority' => 9]))->assertSessionHasErrors('priority');

        $t = Task::query()->where('title', '大事な予定')->first();
        $this->put("/tasks/{$t->id}", $this->form(['title' => '大事な予定', 'priority' => 3]))->assertRedirect();
        $this->assertSame(3, $t->refresh()->priority);
        $this->get("/tasks/{$t->id}/edit")->assertOk()->assertSee('優先度');
    }

    public function test_existing_rows_and_other_creators_get_middle(): void
    {
        // マイグレーション前からある予定（priority を指定せずに入れた行）は「中」
        DB::table('tasks')->insert(['user_id' => auth()->id(), 'title' => '前からある予定', 'date' => '2026-11-05', 'all_day' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(2, (int) DB::table('tasks')->where('title', '前からある予定')->value('priority'));

        // 手帳からの取り込みも「中」
        $this->post('/import', ['items' => [['on' => '1', 'title' => '取り込み', 'date' => '2026-11-07', 'start' => '10:00', 'end' => '11:00']]]);
        $this->assertSame(2, Task::query()->where('title', '取り込み')->value('priority'));
    }

    public function test_today_and_overdue_are_sorted_by_priority_then_time(): void
    {
        $this->task('低 9時', '2026-11-05', '09:00', ['priority' => 3]);
        $this->task('高 15時', '2026-11-05', '15:00', ['priority' => 1]);
        $this->task('中 10時', '2026-11-05', '10:00');
        $this->task('高 終日', '2026-11-05', null, ['priority' => 1]);
        $this->task('期限 低 11/4', '2026-11-04', '09:00', ['priority' => 3]);
        $this->task('期限 高 11/2', '2026-11-02', '09:00', ['priority' => 1]);
        $this->task('期限 中 11/3', '2026-11-03', '09:00');
        $this->task('期限 高 11/1', '2026-11-01', '09:00', ['priority' => 1]);

        $this->get('/?view=month&date=2026-11-10')->assertOk()->assertSeeInOrder([
            '今日やること', '高 終日', '高 15時', '中 10時', '低 9時',
            '期限切れ（未完了）', '期限 高 11/1', '期限 高 11/2', '期限 中 11/3', '期限 低 11/4',
        ]);

        // 今日を選んでいるときの予定一覧も「今日やること」を兼ねるので優先度順
        $this->get('/?view=month&date=2026-11-05')->assertSeeInOrder(['高 終日', '高 15時', '中 10時', '低 9時']);
        // ほかの日を選んだときの予定一覧は時刻順のまま
        $this->task('別日 低 8時', '2026-11-12', '08:00', ['priority' => 3]);
        $this->task('別日 高 9時', '2026-11-12', '09:00', ['priority' => 1]);
        $this->get('/?view=month&date=2026-11-12')->assertSeeInOrder(['別日 低 8時', '別日 高 9時']);
    }

    public function test_seven_day_list_keeps_time_order_and_marks_high(): void
    {
        $this->task('低 9時', '2026-11-05', '09:00', ['priority' => 3]);
        $this->task('高 15時', '2026-11-05', '15:00', ['priority' => 1]);

        $html = $this->get('/?view=list')->assertOk()->assertSeeInOrder(['低 9時', '高 15時'])->getContent();

        $this->assertSame(1, substr_count($html, 'class="prio-high"'));
        $this->assertStringContainsString('title="優先度：高"', $html);
    }

    public function test_priority_does_not_change_notification_times(): void
    {
        $t = $this->task('歯医者', '2026-11-06', '14:00');
        $plan = fn () => array_map(fn ($i) => [$i['key'], $i['at']->format('Y-m-d H:i'), $i['signature']],
            (new NotificationPlanner(Setting::values()))->between(CarbonImmutable::parse('2026-11-05 00:00'), CarbonImmutable::parse('2026-11-06 23:59')));

        $before = $plan();
        $t->update(['priority' => 1]);

        $this->assertSame($before, $plan());
        $this->assertCount(3, $before); // 前日・当日朝・開始前
    }

    // ===== ⑨ サブタスク =====

    public function test_add_edit_delete_and_reorder_subtasks(): void
    {
        $this->post('/tasks', $this->form(['subtasks' => [
            ['title' => '荷物をまとめる'], ['title' => '  '], ['title' => '交通手段を確認する', 'done' => '1'], ['title' => 'ホテルの予約を確認する'],
        ]]))->assertRedirect();
        $t = Task::query()->where('title', '旅行の準備')->first();
        $this->assertSame(['荷物をまとめる', '交通手段を確認する', 'ホテルの予約を確認する'], $t->subtasks->pluck('title')->all()); // 空欄は保存しない
        $this->assertSame([false, true, false], $t->subtasks->pluck('done')->all());
        $this->assertSame([0, 1, 2], $t->subtasks->pluck('sort_order')->all());

        [$a, $b, $c] = $t->subtasks->all();
        // 並び替え（c → a）、a の名前を変更、b を削除、新しい項目を追加
        $this->put("/tasks/{$t->id}", $this->form(['subtasks' => [
            ['id' => $c->id, 'title' => 'ホテルの予約を確認する', 'done' => '0'],
            ['id' => $a->id, 'title' => '荷物をまとめる（前日）', 'done' => '1'],
            ['title' => 'お土産リストを作る'],
        ]]))->assertRedirect();

        $subs = $t->refresh()->subtasks;
        $this->assertSame(['ホテルの予約を確認する', '荷物をまとめる（前日）', 'お土産リストを作る'], $subs->pluck('title')->all());
        $this->assertSame([$c->id, $a->id], $subs->take(2)->pluck('id')->all()); // 同じ行は ID を保つ
        $this->assertModelMissing($b);
        $this->assertTrue($subs[1]->done);
        $this->assertFalse($t->done); // サブタスクは親の完了に影響しない

        $this->get("/tasks/{$t->id}/edit")->assertOk()->assertSeeInOrder(['ホテルの予約を確認する', '荷物をまとめる（前日）', 'お土産リストを作る'])->assertSee('3/20');
    }

    public function test_up_to_20_subtasks(): void
    {
        $items = fn (int $n) => array_map(fn ($i) => ['title' => "項目{$i}"], range(1, $n));

        $this->post('/tasks', $this->form(['title' => '多すぎる', 'subtasks' => $items(21)]))->assertSessionHasErrors('subtasks');
        $this->assertSame(0, Task::query()->where('title', '多すぎる')->count());

        $this->post('/tasks', $this->form(['title' => 'ちょうど20', 'subtasks' => [...$items(20), ['title' => ''], ['title' => ' ']]]))->assertSessionHasNoErrors();
        $this->assertSame(20, Task::query()->where('title', 'ちょうど20')->first()->subtasks()->count());
    }

    public function test_a_subtask_id_from_another_task_is_not_taken_over(): void
    {
        $other = $this->task('ほかの予定', '2026-11-06', '10:00');
        $foreign = $other->subtasks()->create(['title' => 'ほかの項目', 'sort_order' => 0]);
        $mine = $this->task('自分の予定', '2026-11-06', '11:00');

        $this->put("/tasks/{$mine->id}", $this->form(['title' => '自分の予定', 'subtasks' => [['id' => $foreign->id, 'title' => '書きかえ']]]));

        $this->assertSame('ほかの項目', $foreign->refresh()->title);
        $this->assertSame($other->id, $foreign->task_id);
        $this->assertSame(['書きかえ'], $mine->subtasks()->pluck('title')->all());
    }

    public function test_checking_all_subtasks_asks_but_does_not_complete_the_task(): void
    {
        $t = $this->task('旅行の準備', '2026-11-05', '10:00');
        $s1 = $t->subtasks()->create(['title' => '荷物', 'sort_order' => 0]);
        $s2 = $t->subtasks()->create(['title' => '切符', 'sort_order' => 1]);

        $this->patchJson("/subtasks/{$s1->id}/done", ['done' => 1])->assertOk()
            ->assertJson(['done' => true, 'task_id' => $t->id, 'done_count' => 1, 'total' => 2, 'ask_complete' => false]);
        $this->patchJson("/subtasks/{$s2->id}/done", ['done' => 1])->assertOk()
            ->assertJson(['done_count' => 2, 'total' => 2, 'ask_complete' => true]);
        $this->assertFalse($t->refresh()->done); // 自動では完了にしない

        // 「まだ完了しない」→ 何も送らない（親は未完了のまま）。「完了する」→ 親を完了にする
        $this->patchJson("/tasks/{$t->id}/done", ['done' => 1])->assertOk();
        $this->assertTrue($t->refresh()->done);

        // 親が完了済みなら聞かない
        $this->patchJson("/subtasks/{$s2->id}/done", ['done' => 0])->assertJson(['ask_complete' => false]);
        $this->patchJson("/subtasks/{$s2->id}/done", ['done' => 1])->assertJson(['ask_complete' => false]);
    }

    public function test_the_question_appears_without_javascript_too(): void
    {
        $t = $this->task('旅行の準備', '2026-11-05', '10:00');
        $s = $t->subtasks()->create(['title' => '荷物', 'sort_order' => 0]);

        $this->from('/?view=month&date=2026-11-05')->patch("/subtasks/{$s->id}/done", ['done' => 1])
            ->assertRedirect('/?view=month&date=2026-11-05')->assertSessionHas('ask_complete', $t->id);

        $asked = $this->withSession(['ask_complete' => $t->id])->get('/?view=month&date=2026-11-05')->getContent();
        $this->assertStringContainsString('すべてのサブタスクが完了しました。このタスクも完了にしますか？', $asked);
        $this->assertDoesNotMatchRegularExpression('/data-subtask-ask="'.$t->id.'"\s+hidden/', $asked);

        $normal = $this->get('/?view=month&date=2026-11-05')->getContent();
        $this->assertMatchesRegularExpression('/data-subtask-ask="'.$t->id.'"\s+hidden/', $normal);
    }

    public function test_subtasks_do_not_stop_line_but_completing_the_parent_does(): void
    {
        config(['line.driver' => 'line']);
        LineConnection::query()->create(['channel_access_token' => 'test-token-0123456789abcdef', 'line_user_id' => 'U0123456789abcdef0123456789abcdef']);
        Http::fake(['api.line.me/*' => Http::response([])]);
        $a = $this->task('サブだけ完了', '2026-11-05', '14:00');
        $a->subtasks()->create(['title' => '準備', 'done' => true, 'sort_order' => 0]);
        $b = $this->task('親を完了', '2026-11-05', '14:00');
        $b->subtasks()->create(['title' => '準備', 'sort_order' => 0]);
        $this->patch("/tasks/{$b->id}/done", ['done' => 1]);

        $this->travelTo(CarbonImmutable::parse('2026-11-05 13:00'));
        app(NotificationDispatcher::class)->run();

        $texts = Http::recorded(fn (Request $r) => str_contains($r->url(), '/message/push'))->map(fn ($p) => $p[0]['messages'][0]['text'])->all();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('サブだけ完了', $texts[0]);
        $this->assertSame(1, NotificationLog::query()->where('type', 'before')->count());
    }

    public function test_deleting_a_task_deletes_its_subtasks(): void
    {
        $t = $this->task('消す予定', '2026-11-06', '10:00');
        $s = $t->subtasks()->create(['title' => '項目', 'sort_order' => 0]);

        $this->delete("/tasks/{$t->id}")->assertRedirect();

        $this->assertModelMissing($s);
    }

    public function test_rows_show_the_subtask_count(): void
    {
        $t = $this->task('旅行の準備', '2026-11-05', '10:00');
        $t->subtasks()->create(['title' => '荷物', 'done' => true, 'sort_order' => 0]);
        $t->subtasks()->create(['title' => '切符', 'sort_order' => 1]);
        $t->subtasks()->create(['title' => 'ホテル', 'sort_order' => 2]);

        $this->get('/?view=month&date=2026-11-05')->assertSee('1/3')->assertSee('切符')->assertSee(route('subtasks.done', $t->subtasks[1]));
        $this->get('/?view=list')->assertSee('1/3');
        $this->get('/?view=month&date=2026-11-10')->assertSee('1/3'); // 今日やること
    }

    // ===== ⑩ 達成状況 =====

    public function test_progress_counts_only_todays_parent_tasks(): void
    {
        foreach ([true, true, true, false, false] as $i => $done) {
            $this->task("今日{$i}", '2026-11-05', sprintf('%02d:00', 10 + $i), ['done' => $done]);
        }
        $this->task('昨日の未完了', '2026-11-04', '10:00');                    // 期限切れ → 数えない
        $this->task('明日の予定', '2026-11-06', '10:00', ['done' => true]);     // 明日 → 数えない
        Task::query()->where('title', '今日3')->first()->subtasks()->create(['title' => 'サブ', 'done' => true, 'sort_order' => 0]); // サブタスク → 数えない

        $this->get('/?view=month&date=2026-11-05')->assertOk()
            ->assertViewHas('progress', ['done' => 3, 'total' => 5, 'percent' => 60])
            ->assertSee('今日の達成状況：')->assertSee('3/5件')->assertSee('aria-valuenow="60"', false)->assertSee('width: 60%', false);

        // 完了チェックで更新される（返事に新しい達成状況が入る）
        $t = Task::query()->where('title', '今日3')->first();
        $this->patchJson("/tasks/{$t->id}/done", ['done' => 1])->assertJson(['progress' => ['done' => 4, 'total' => 5, 'percent' => 80]]);
        $y = Task::query()->where('title', '昨日の未完了')->first();
        $this->patchJson("/tasks/{$y->id}/done", ['done' => 1])->assertJson(['progress' => ['done' => 4, 'total' => 5, 'percent' => 80]]);
    }

    public function test_progress_when_there_is_nothing_today(): void
    {
        $this->task('明日の予定', '2026-11-06', '10:00');

        $html = $this->get('/?view=month&date=2026-11-05')->assertOk()
            ->assertViewHas('progress', ['done' => 0, 'total' => 0, 'percent' => 0])
            ->assertSee('今日の予定はありません')->getContent();
        $this->assertSame(1, substr_count($html, '今日の予定はありません'));
        $this->get('/?view=month&date=2026-11-10')->assertSee('今日の予定はありません'); // 別の日を選んでいても出る
    }

    // ===== ⑦ クイック登録 =====

    private function quick(string $text, string $now = '2026-11-05 09:00'): array
    {
        $p = (new TaskTextParser(Category::ordered()))->parseQuick($text, CarbonImmutable::parse($now));

        return [$p['date'], $p['start'], $p['end'], $p['title'], $p['warnings']];
    }

    public function test_quick_reads_relative_days_weekdays_and_times(): void
    {
        // 2026-11-05 は木曜日
        $this->assertSame(['2026-11-06', '10:00', '11:00', '歯医者', []], $this->quick('明日10時 歯医者'));
        $this->assertSame(['2026-11-05', '15:00', '16:00', '買い物', []], $this->quick('今日15時 買い物'));
        $this->assertSame(['2026-11-07', '13:00', '14:00', '美容院', []], $this->quick('明後日13時 美容院'));
        $this->assertSame(['2026-11-06', '18:00', '19:00', '会議', []], $this->quick('金曜日18時 会議'));
        $this->assertSame(['2026-11-05', '18:00', '19:00', '定例', []], $this->quick('木曜18時 定例'));            // 今日が木曜 → 今日
        $this->assertSame(['2026-11-11', '10:00', '11:00', '面談', []], $this->quick('水曜日 10時 面談'));         // 次の水曜
        $this->assertSame(['2026-11-09', '09:00', '10:00', '打ち合わせ', []], $this->quick('来週月曜日9時 打ち合わせ'));
        $this->assertSame(['2026-11-15', null, null, '掃除', ['時刻が読み取れませんでした。時刻を入れるか「終日」にしてください']], $this->quick('来週日曜 掃除'));
        $this->assertSame(['2026-11-15', '14:00', '15:00', 'ランチ', []], $this->quick('11/15 14時 ランチ'));
        $this->assertSame(['2026-12-25', '10:00', '11:00', 'クリスマス会', []], $this->quick('12月25日 午前10時 クリスマス会'));
        $this->assertSame(['2026-11-06', '10:30', '11:30', '面接', []], $this->quick('明日10時半 面接'));
        $this->assertSame(['2026-11-06', '19:00', '20:30', '食事', []], $this->quick('明日 19:00-20:30 食事'));
    }

    public function test_quick_does_not_guess_what_it_cannot_read(): void
    {
        [$date, $start, , $title, $w] = $this->quick('午後3時 郵便局');
        $this->assertSame([null, '15:00', '郵便局'], [$date, $start, $title]);
        $this->assertSame(['日付が読み取れませんでした。日付を選んでください'], $w);

        [$date, $start, , $title, $w] = $this->quick('ランチ');
        $this->assertSame([null, null, 'ランチ'], [$date, $start, $title]);
        $this->assertCount(2, $w);

        [, $start, , , $w] = $this->quick('明日3時 打ち合わせ');
        $this->assertSame('03:00', $start);
        $this->assertSame(['3時は午前3時として読み取りました。午後なら時刻を直してください'], $w);

        [, , , $title, $w] = $this->quick('明日10時');
        $this->assertSame('', $title);
        $this->assertSame(['予定名が読み取れませんでした。予定名を入れてください'], $w);
    }

    public function test_quick_year_boundary_and_past_dates(): void
    {
        // 年末：月だけの日付は年をまたぐ。明後日・来週も年をまたぐ
        $this->assertSame('2027-01-03', $this->quick('1/3 初詣', '2026-12-30 10:00')[0]);
        $this->assertSame('2027-01-01', $this->quick('明後日 元日', '2026-12-30 10:00')[0]);
        $this->assertSame('2027-01-04', $this->quick('来週月曜 仕事始め', '2026-12-30 10:00')[0]);
        $this->assertSame('2027-01-01', $this->quick('金曜 休み', '2026-12-30 10:00')[0]);

        // 過去の日付・過ぎた時刻は、そのままにして知らせる（勝手に来年にしない）
        [$date, , , , $w] = $this->quick('11/1 14時 振り返り');
        $this->assertSame('2026-11-01', $date);
        $this->assertSame(['過去の日付（11月1日(日)）です。日付を確認してください'], $w);
        $this->assertSame(['今日のもう過ぎた時刻（08:00）です。時刻を確認してください'], $this->quick('今日8時 朝会')[4]);
    }

    public function test_quick_fills_the_form_without_saving(): void
    {
        $this->get('/')->assertSee('name="q"', false)->assertSee('例：明日10時 歯医者');

        $this->get('/tasks/create?q='.urlencode('明日10時 歯医者'))->assertOk()
            ->assertSee('「明日10時 歯医者」から読み取りました')->assertSee('まだ保存していません')
            ->assertSee('value="2026-11-06"', false)->assertSee('value="10:00"', false)->assertSee('value="歯医者"', false);
        $this->assertSame(0, Task::count());

        // 読み取れなかった日付・時刻は空のまま（勝手に決めない）
        $html = $this->get('/tasks/create?q='.urlencode('ランチ'))->assertOk()
            ->assertSee('日付が読み取れませんでした')->assertSee('時刻が読み取れませんでした')->getContent();
        $this->assertMatchesRegularExpression('/name="date" value=""/', $html);
        $this->assertMatchesRegularExpression('/name="start_time" value=""/', $html);
        $this->assertSame(0, Task::count());

        // 保存ボタンを押して初めて登録される
        $this->post('/tasks', $this->form(['title' => '歯医者', 'date' => '2026-11-06']))->assertRedirect();
        $this->assertSame(1, Task::count());
    }

    // ===== バックアップと復元 =====

    public function test_backup_v3_keeps_priority_and_subtasks_and_old_files_still_work(): void
    {
        LineConnection::query()->create(['channel_access_token' => 'secret-token-must-not-be-exported', 'line_user_id' => 'U0123456789abcdef0123456789abcdef']);
        $t = $this->task('旅行の準備', '2026-11-06', '10:00', ['priority' => 1]);
        $t->subtasks()->create(['title' => '切符', 'done' => true, 'sort_order' => 1]);
        $t->subtasks()->create(['title' => '荷物', 'sort_order' => 0]);

        $json = Backup::export();
        $this->assertSame(4, $json['version']); // 第3段階で version 4（繰り返し予定）に。優先度・サブタスクの形は 3 と同じ
        $this->assertSame(1, $json['tasks'][0]['priority']);
        $this->assertSame([['title' => '荷物', 'done' => false, 'sort_order' => 0], ['title' => '切符', 'done' => true, 'sort_order' => 1]], $json['tasks'][0]['subtasks']);
        $raw = json_encode($json, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('secret-token', $raw);
        $this->assertStringNotContainsString('password', $raw);

        Backup::restore($json);
        $r = Task::query()->where('title', '旅行の準備')->first();
        $this->assertNotSame($t->id, $r->id);
        $this->assertSame(1, $r->priority);
        $this->assertSame(['荷物', '切符'], $r->subtasks->pluck('title')->all());
        $this->assertSame([false, true], $r->subtasks->pluck('done')->all());
        $this->assertSame(0, Subtask::query()->where('task_id', $t->id)->count()); // 古い予定のサブタスクは残らない

        // 前の形式（version 2：優先度・サブタスクなし）
        $old = $json;
        $old['version'] = 2;
        foreach ($old['tasks'] as &$task) {
            unset($task['priority'], $task['subtasks']);
        }
        unset($task);
        Backup::restore($old);
        $r = Task::query()->where('title', '旅行の準備')->first();
        $this->assertSame(2, $r->priority);
        $this->assertSame(0, $r->subtasks()->count());
    }
}
