<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Invitation;
use App\Models\LineConnection;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Support\Owner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * 1人用 → 複数ユーザーのマイグレーション（2026_11_05_000001〜000003）
 * 本番と同じ「1人用の古い形の DB にデータが入っている」状態を作ってから流し、何も失われず、持ち主が正しく決まることを確かめる
 * （DB の形を変えるので RefreshDatabase は使わない。前後で migrate:fresh して、ほかのテストに影響を残さない）
 */
class OwnerMigrationTest extends TestCase
{
    /** 新しいマイグレーションの数（元に戻して、1人用の形にする） */
    private const NEW_MIGRATIONS = 3;

    private const LEGACY_TABLES = ['categories', 'tasks', 'subtasks', 'task_series', 'task_series_skips', 'task_reminders', 'notification_logs', 'line_connections', 'settings'];

    private const LINE_ID = 'U0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('migrate:rollback', ['--step' => self::NEW_MIGRATIONS, '--force' => true]); // 1人用の形（いまの本番と同じ）
        $this->assertFalse(Schema::hasColumn('tasks', 'user_id'));
        $this->assertFalse(Schema::hasTable('invitations'));
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    /** 1人用の DB に、本番で起こりうるデータを一通り入れる（ユーザー ID はわざと 1 にしない） */
    private function seedLegacy(int $userId = 7): void
    {
        $now = '2026-11-04 12:00:00';
        DB::table('users')->insert(['id' => $userId, 'name' => 'いまの持ち主', 'email' => 'owner@example.com', 'password' => Hash::make('the-original-password'), 'remember_token' => 'keep-me', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('categories')->insert([
            ['id' => 11, 'name' => '仕事', 'color' => '#A3C1DE', 'key' => 'work', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'name' => '家族', 'color' => '#E8BAC5', 'key' => 'family', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('task_series')->insert(['id' => 21, 'freq' => 'weekly', 'weekdays' => '1,3', 'starts_on' => '2026-11-02', 'generated_until' => '2027-01-31', 'title' => '週の定例', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'category_id' => 11, 'priority' => 2, 'subtasks' => json_encode(['議事録']), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('task_series_skips')->insert(['series_id' => 21, 'date' => '2026-11-09', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tasks')->insert([
            ['id' => 31, 'title' => '移行前の通院', 'date' => '2026-11-05', 'all_day' => false, 'start_time' => '14:00:00', 'end_time' => '15:00:00', 'category_id' => 12, 'memo' => 'メモ', 'done' => false, 'priority' => 1, 'series_id' => null, 'series_date' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 32, 'title' => '週の定例', 'date' => '2026-11-04', 'all_day' => false, 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'category_id' => 11, 'memo' => null, 'done' => true, 'priority' => 2, 'series_id' => 21, 'series_date' => '2026-11-04', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('subtasks')->insert(['task_id' => 32, 'title' => '議事録', 'done' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('task_reminders')->insert(['task_id' => 31, 'remind_at' => '2026-11-05 13:30:00', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('notification_logs')->insert(['dedupe_key' => 'morning:2026-11-05', 'type' => 'morning', 'task_id' => null, 'scheduled_at' => '2026-11-05 08:00:00', 'status' => 'simulated', 'attempts' => 1, 'message' => '☀️ 今日の予定', 'sent_at' => '2026-11-05 08:00:00', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('line_connections')->insert(['channel_access_token' => Crypt::encryptString('legacy-token-0123456789'), 'user_id' => Crypt::encryptString(self::LINE_ID), 'status' => 'ok', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('settings')->insert([
            ['key' => 'morning_time', 'value' => '07:30', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'notify_resume_after', 'value' => '2026-11-01 00:00:00', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /** 持ち主の列を除いた、全テーブルの中身（前後で同じかを比べる） */
    private function snapshot(): array
    {
        $out = [];
        foreach (self::LEGACY_TABLES as $t) {
            $out[$t] = DB::table($t)->orderBy($t === 'settings' ? 'key' : 'id')->get()
                ->map(function ($row) use ($t) {
                    $row = (array) $row;
                    if ($t === 'line_connections') {
                        // LINE のユーザーID：1人用では user_id、新しい形では line_user_id（名前だけ変わる）
                        $row['line'] = array_key_exists('line_user_id', $row) ? $row['line_user_id'] : $row['user_id'];
                        unset($row['line_user_id']);
                    }
                    unset($row['user_id']);

                    return $row;
                })->all();
        }

        return $out;
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_existing_data_of_the_single_user_is_kept_and_owned_by_that_user(): void
    {
        $this->seedLegacy(7);
        $user = (array) DB::table('users')->first();
        $before = $this->snapshot();

        $this->migrate();

        // 中身は1件も変わらない・消えない（ユーザーの ID・パスワードのハッシュも）
        $this->assertEquals($before, $this->snapshot());
        $this->assertEquals($user, (array) DB::table('users')->first());
        $this->assertSame(1, DB::table('users')->count());
        // 持ち主はいまの1人（ID 7）。1 と決めつけていない
        foreach (['categories', 'tasks', 'task_series', 'notification_logs', 'line_connections'] as $t) {
            $this->assertSame([7], DB::table($t)->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all(), $t);
        }
        // 設定は user_settings にコピー（settings テーブルも残す）
        $this->assertSame(['morning_time' => '07:30', 'notify_resume_after' => '2026-11-01 00:00:00'],
            DB::table('user_settings')->where('user_id', 7)->orderBy('key')->pluck('value', 'key')->all());
        $this->assertSame(2, DB::table('settings')->count());

        // アプリから見ても、そのまま使える
        $owner = User::query()->findOrFail(7);
        $this->assertTrue(Hash::check('the-original-password', $owner->password));
        Owner::run($owner, function () {
            $this->assertSame(['移行前の通院', '週の定例'], Task::query()->orderBy('id')->pluck('title')->all());
            $this->assertSame(['仕事', '家族'], Category::ordered()->pluck('name')->all());
            $this->assertSame('07:30', Setting::values()['morning_time']);
            $this->assertSame(self::LINE_ID, LineConnection::current()->line_user_id);
            $this->assertSame('legacy-token-0123456789', LineConnection::current()->channel_access_token);
            $this->assertSame(1, Task::query()->findOrFail(32)->subtasks()->count());
            $this->assertSame(1, Task::query()->findOrFail(31)->reminders()->count());
        });
        $this->actingAs($owner)->get('/?view=day&date=2026-11-05')->assertOk()->assertSee('移行前の通院');

        // そのあと招待で2人目が登録しても、1人目のデータは変わらず、2人目には見えない
        $token = Invitation::newToken();
        (new Invitation)->forceFill(['email' => 'new@example.com', 'token_hash' => Invitation::hashToken($token), 'expires_at' => now()->addDays(7)])->save();
        $this->post('/logout');
        $this->post('/invite', ['token' => $token]);
        $this->post('/register', ['name' => '新しい人', 'email' => 'new@example.com', 'password' => 'new-person-password', 'password_confirmation' => 'new-person-password'])->assertRedirect('/');
        $this->get('/?view=day&date=2026-11-05')->assertOk()->assertDontSee('移行前の通院');
        $after = $this->snapshot();
        $this->assertEquals($before['tasks'], $after['tasks']);
        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_stops_without_changing_anything_when_there_are_two_users(): void
    {
        $this->seedLegacy(7);
        DB::table('users')->insert(['id' => 8, 'name' => '想定外', 'email' => 'unexpected@example.com', 'password' => Hash::make('x-password-1234'), 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->snapshot();

        try {
            $this->migrate();
            $this->fail('止まるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ユーザーが 2 人', $e->getMessage());
        }
        // 持ち主を決めるマイグレーションは何も変えていない（列も足していない・データも同じ）
        foreach (['categories', 'tasks', 'task_series', 'notification_logs'] as $t) {
            $this->assertFalse(Schema::hasColumn($t, 'user_id'), $t);
        }
        $this->assertFalse(Schema::hasColumn('line_connections', 'line_user_id'));
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(0, DB::table('user_settings')->count());
        $this->assertNotContains('2026_11_05_000003_add_owner_to_user_data', DB::table('migrations')->pluck('migration')->all());
    }

    public function test_stops_when_there_is_data_but_no_user(): void
    {
        DB::table('categories')->insert(['name' => '仕事', 'color' => '#A3C1DE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->expectExceptionMessage('ユーザーが 0 人');
        $this->migrate();
    }

    public function test_can_be_run_again_after_stopping_midway(): void
    {
        $this->seedLegacy(7);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_05_000001_create_invitations_table.php', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_05_000002_create_user_settings_table.php', '--force' => true]);
        // 途中で止まった状態を作る：LINE の列の名前だけ変わり、tasks に空の user_id が足された
        Schema::table('line_connections', fn ($t) => $t->renameColumn('user_id', 'line_user_id'));
        Schema::table('tasks', fn ($t) => $t->unsignedBigInteger('user_id')->nullable());

        $this->migrate();

        $this->assertSame([7], DB::table('tasks')->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([7], DB::table('line_connections')->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(self::LINE_ID, Crypt::decryptString(DB::table('line_connections')->value('line_user_id')));
        $this->assertSame(2, DB::table('user_settings')->count());
    }

    public function test_a_new_empty_database_needs_no_owner(): void
    {
        $this->migrate();
        $this->assertTrue(Schema::hasColumn('tasks', 'user_id'));
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_rolling_back_restores_the_single_user_shape_and_refuses_with_two_users(): void
    {
        $this->seedLegacy(7);
        $this->migrate();
        Owner::run(7, fn () => Setting::put('morning_time', '06:45')); // 新しい形になってから変えた設定

        // 2人目がいると、1人用に戻すとデータが混ざって見えるので、戻さない
        DB::table('users')->insert(['id' => 9, 'name' => '2人目', 'email' => 'second@example.com', 'password' => Hash::make('x-password-1234'), 'created_at' => now(), 'updated_at' => now()]);
        try {
            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
            $this->fail('止まるはず');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1人用に戻せません', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('tasks', 'user_id'));

        // 1人だけなら戻せる。データは残り、設定は settings に、LINE のユーザーIDは元の列に戻る
        DB::table('users')->where('id', 9)->delete();
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('tasks', 'user_id'));
        $this->assertSame(2, DB::table('tasks')->count());
        $this->assertSame('06:45', DB::table('settings')->where('key', 'morning_time')->value('value'));
        $this->assertSame(self::LINE_ID, Crypt::decryptString(DB::table('line_connections')->value('user_id')));
        $this->assertTrue(Schema::hasIndex('notification_logs', ['dedupe_key'], 'unique'));

        // もう一度進めても同じ結果になる
        $this->migrate();
        $this->assertSame([7], DB::table('tasks')->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all());
    }
}
