<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CalendarAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 10, 25, 9, 30));
        $this->actingAs(User::factory()->create()); // 画面はログインが必要。データはユーザーごとなので、先にログインする
        $this->seed();
    }

    public function test_calendar_views_render(): void
    {
        $this->get('/')->assertOk()->assertSee('2026年10月')->assertSee('チーム定例');
        $this->get('/?view=week&date=2026-10-25')->assertOk()->assertSee('10月25日 – 31日')->assertSee('歯医者');
        $this->get('/?view=day&date=2026-10-26')->assertOk()->assertSee('10月26日(月)')->assertSee('資料の提出');
        // 最後に見ていた表示を覚えている
        $this->get('/')->assertOk()->assertSee('10月25日(日)');
    }

    public function test_create_and_update_and_delete_task(): void
    {
        $cat = Category::query()->where('key', 'health')->first();

        $this->get('/tasks/create?date=2026-10-30&start=15:00')->assertOk()->assertSee('value="15:00"', false);

        // 終了が空 → 開始の1時間後
        $this->post('/tasks', [
            'title' => '整体', 'date' => '2026-10-30', 'start_time' => '15:00', 'end_time' => '',
            'category_id' => $cat->id, 'notify_morning' => '1', 'notify_before_minutes' => 30,
        ])->assertRedirect()->assertSessionHas('status', '予定を追加しました');

        $task = Task::query()->where('title', '整体')->firstOrFail();
        $this->assertSame('16:00', $task->end);
        $this->assertFalse($task->notify_day_before); // チェックなし
        $this->assertTrue($task->notify_morning);

        // 終日にすると時刻は消える。「保存してGoogleに登録」
        $this->put("/tasks/{$task->id}", [
            'title' => '整体', 'date' => '2026-10-30', 'all_day' => '1',
            'category_id' => $cat->id, 'notify_before_minutes' => 60, 'action' => 'gcal',
        ])->assertSessionHas('gcal_task', $task->id);
        $task->refresh();
        $this->assertTrue($task->all_day);
        $this->assertNull($task->start_time);

        $res = $this->post("/tasks/{$task->id}/google");
        $res->assertRedirect();
        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?action=TEMPLATE', $res->headers->get('Location'));
        $this->assertStringContainsString('dates=20261030%2F20261031', $res->headers->get('Location'));
        $this->assertTrue($task->refresh()->gcal_added);

        $this->delete("/tasks/{$task->id}")->assertRedirect();
        $this->assertModelMissing($task);
    }

    public function test_validation_messages(): void
    {
        $this->from('/tasks/create')->post('/tasks', ['title' => '', 'date' => '2026-10-30', 'notify_before_minutes' => 60])
            ->assertRedirect('/tasks/create')
            ->assertSessionHasErrors(['title' => 'タイトルを入力してください', 'start_time']);
    }

    public function test_import_flow(): void
    {
        $this->post('/import/preview', ['text' => "10/27(火)\n14:00 歯医者\n資料の提出", 'base' => '2026-10-25'])
            ->assertOk()->assertSee('タスク候補 2件')->assertSee('value="歯医者"', false);

        $before = Task::count();
        $res = $this->post('/import', ['items' => [
            ['on' => '1', 'title' => '歯医者', 'date' => '2026-10-27', 'start' => '14:00', 'end' => '', 'category_id' => Category::first()->id],
            ['title' => '登録しない', 'date' => '2026-10-27'],
            ['on' => '1', 'title' => '資料の提出', 'date' => '2026-10-27'],
        ]]);
        $res->assertRedirect();
        $this->assertSame($before + 2, Task::count());
        $this->get($res->headers->get('Location'))->assertOk()->assertSee('2件を登録しました');

        $this->post('/import/preview', ['text' => '   '])->assertSessionHasErrors('text');
    }

    public function test_settings_and_categories(): void
    {
        $this->get('/settings')->assertOk()->assertSee('これから届く通知')->assertSee('今日の予定');

        $this->put('/settings', ['day_before_time' => '22:00', 'morning_time' => '07:30', 'default_before' => 15])->assertRedirect();
        $this->assertSame(['default_before' => 15, 'day_before_time' => '22:00', 'morning_time' => '07:30', 'month_show_titles' => 0], Setting::values());

        $work = Category::query()->where('key', 'work')->first();
        $this->put('/categories', ['categories' => [$work->id => ['name' => 'しごと', 'color' => '#112233']]])->assertRedirect();
        $this->assertSame('しごと', $work->refresh()->name);

        $first = Category::ordered()->first();
        $used = $work->tasks()->count();
        $this->delete("/categories/{$work->id}")->assertRedirect();
        $this->assertModelMissing($work);
        $this->assertGreaterThan(0, $used);
        $this->assertSame(0, Task::query()->whereNull('category_id')->count());
    }

    public function test_export_and_restore_including_prototype_backup(): void
    {
        $this->get('/export/ics')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('SUMMARY:チーム定例');

        $json = $this->get('/export/json')->assertOk()->json();
        $this->assertCount(10, $json['tasks']);

        // 試作版（localStorage）の書き出し形式
        $legacy = [
            'categories' => [['id' => 'work', 'name' => '仕事', 'color' => '#4A7FA0'], ['id' => 'abc', 'name' => '趣味', 'color' => '#C47FA2']],
            'settings' => ['defaultBefore' => 30, 'dayBeforeTime' => '21:00', 'morningTime' => '07:00'],
            'tasks' => [
                ['id' => 'x1', 'title' => '会議', 'date' => '2026-11-01', 'allDay' => false, 'start' => '10:00', 'end' => '11:00', 'categoryId' => 'work', 'notify' => ['dayBefore' => false, 'morning' => true, 'before' => 15]],
                ['id' => 'x2', 'title' => '映画', 'date' => '2026-11-02', 'allDay' => true, 'start' => '', 'end' => '', 'categoryId' => 'abc'],
            ],
        ];
        $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($legacy));
        $this->post('/restore', ['backup' => $file])->assertRedirect('/')->assertSessionHas('status', 'バックアップから 2件を戻しました');

        $this->assertSame(2, Task::count());
        $meeting = Task::query()->where('title', '会議')->first();
        $this->assertSame('仕事', $meeting->category->name);
        $this->assertSame('work', $meeting->category->key);
        $this->assertFalse($meeting->notify_day_before);
        $this->assertSame(15, $meeting->notify_before_minutes);
        $this->assertSame('趣味', Task::query()->where('title', '映画')->first()->category->name);
        $this->assertSame('21:00', Setting::values()['day_before_time']);

        // 書き出したもの（新形式）も戻せる
        $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($json));
        $this->post('/restore', ['backup' => $file])->assertSessionHas('status', 'バックアップから 10件を戻しました');

        $this->delete('/data')->assertRedirect('/');
        $this->assertSame(0, Task::count());
        $this->assertSame(6, Category::count());
    }
}
