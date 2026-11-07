<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Support\Owner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** ログインのメールアドレス・パスワードを変えるコマンド（php artisan user:credentials） */
class UserCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'old-password-1234';

    private const NEW_PASSWORD = 'new-password-for-demo';

    private const ASK_PASSWORD = 'パスワード（12文字以上。入力しても表示されません）';

    private const ASK_AGAIN = '確認のため、もう一度入力してください';

    private const CONFIRM = 'この内容で変更しますか？';

    private function owner(): User
    {
        return User::factory()->create(['name' => '持ち主', 'email' => 'owner@example.com', 'password' => self::OLD_PASSWORD]);
    }

    /** ユーザー以外のデータの件数（変わらないことを確かめる） */
    private function dataCounts(): array
    {
        return collect(['tasks', 'categories', 'user_settings', 'subtasks', 'task_series', 'line_connections', 'notification_logs'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_changes_email_and_password_of_the_same_user_and_keeps_data(): void
    {
        config(['session.driver' => 'database']); // 本番と同じ（テストの標準は array）
        $user = $this->owner();
        Owner::run($user, fn () => Task::create(['title' => '発表の準備', 'date' => '2026-11-10']));
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $user->forceFill(['remember_token' => 'remember-me'])->save();
        $before = $this->dataCounts();

        $this->artisan('user:credentials', ['--email' => 'Presenter.Demo@Example.com', '--password' => true])
            ->expectsQuestion(self::ASK_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::ASK_AGAIN, self::NEW_PASSWORD)
            ->expectsOutputToContain('owner@example.com → presenter.demo@example.com')
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->doesntExpectOutputToContain(self::NEW_PASSWORD)
            ->assertSuccessful();

        $this->assertSame(1, User::count());
        $fresh = User::first();
        $this->assertSame($user->id, $fresh->id);
        $this->assertSame('持ち主', $fresh->name);
        $this->assertSame('presenter.demo@example.com', $fresh->email);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertNotSame(self::NEW_PASSWORD, $fresh->password); // ハッシュで保存
        $this->assertNull($fresh->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count()); // ほかの端末のログインを切る
        $this->assertSame($before, $this->dataCounts());

        // 新しいメールアドレスとパスワードでログインできる。古いものではできない
        $this->post('/login', ['email' => 'owner@example.com', 'password' => self::OLD_PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'presenter.demo@example.com', 'password' => self::NEW_PASSWORD])->assertRedirect('/');
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_email_only_keeps_the_password(): void
    {
        $this->owner();
        $this->artisan('user:credentials', ['--email' => 'next-owner@example.com'])
            ->expectsOutputToContain('パスワード：変えない')
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->assertSuccessful();

        $u = User::first();
        $this->assertSame('next-owner@example.com', $u->email);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $u->password));
    }

    public function test_password_only_keeps_the_email(): void
    {
        $this->owner();
        $this->artisan('user:credentials', ['--password' => true])
            ->expectsQuestion(self::ASK_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::ASK_AGAIN, self::NEW_PASSWORD)
            ->expectsOutputToContain('変えない（owner@example.com）')
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->assertSuccessful();

        $u = User::first();
        $this->assertSame('owner@example.com', $u->email);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $u->password));
    }

    public function test_asks_what_to_change_when_no_option_is_given(): void
    {
        $this->owner();
        $this->artisan('user:credentials')
            ->expectsQuestion('新しいメールアドレス（変えないときは空のまま Enter）', '')
            ->expectsConfirmation('パスワードも変えますか？', 'yes')
            ->expectsQuestion(self::ASK_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::ASK_AGAIN, self::NEW_PASSWORD)
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->assertSuccessful();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, User::first()->password));
        $this->assertSame('owner@example.com', User::first()->email);
    }

    public function test_answering_no_changes_nothing(): void
    {
        $user = $this->owner();
        $hash = $user->password;
        $this->artisan('user:credentials', ['--email' => 'other@example.com', '--password' => true])
            ->expectsQuestion(self::ASK_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::ASK_AGAIN, self::NEW_PASSWORD)
            ->expectsConfirmation(self::CONFIRM, 'no')
            ->assertFailed();

        $this->assertSame('owner@example.com', User::first()->email);
        $this->assertSame($hash, User::first()->password);
    }

    public function test_rejects_bad_input_without_changing_anything(): void
    {
        $user = $this->owner();
        $hash = $user->password;

        $this->artisan('user:credentials', ['--email' => 'not-an-email'])->assertFailed();
        $this->artisan('user:credentials', ['--password' => true])
            ->expectsQuestion(self::ASK_PASSWORD, 'short')->assertFailed();
        $this->artisan('user:credentials', ['--password' => true])
            ->expectsQuestion(self::ASK_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::ASK_AGAIN, 'different-password')->assertFailed();
        $this->artisan('user:credentials', ['--no-interaction' => true])->assertFailed(); // 変える内容がない

        $this->assertSame('owner@example.com', User::first()->email);
        $this->assertSame($hash, User::first()->password);
    }

    public function test_never_adds_users_and_refuses_when_not_exactly_one(): void
    {
        $this->artisan('user:credentials', ['--email' => 'a@example.com'])->assertFailed();
        $this->assertSame(0, User::count());

        User::factory()->count(2)->create();
        $this->artisan('user:credentials', ['--email' => 'a@example.com'])->assertFailed();
        $this->assertSame(2, User::count());
        $this->assertSame(0, User::where('email', 'a@example.com')->count());
    }
}
