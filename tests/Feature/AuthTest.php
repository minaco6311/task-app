<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** ログイン（1人で使う。画面からの新規登録はない） */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function user(): User
    {
        return User::factory()->create(['email' => 'owner@example.com', 'password' => self::PASSWORD]);
    }

    public function test_every_page_needs_login(): void
    {
        foreach (['/', '/tasks/create', '/import', '/settings', '/settings/line', '/export/json'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->delete('/data')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('ログイン')->assertDontSee('新規登録する');
    }

    public function test_there_is_no_sign_up(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'x@example.com', 'password' => 'x'])->assertNotFound();
    }

    public function test_login_and_logout(): void
    {
        $this->user();

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'Owner@Example.com', 'password' => self::PASSWORD])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->get('/login')->assertRedirect('/');

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => 'owner@example.com', 'password' => self::PASSWORD])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_create_user_command_asks_for_the_password(): void
    {
        $this->artisan('user:create', ['--name' => '持ち主', '--email' => 'Owner@Example.com'])
            ->expectsQuestion('パスワード（12文字以上。入力しても表示されません）', self::PASSWORD)
            ->expectsQuestion('確認のため、もう一度入力してください', self::PASSWORD)
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('owner@example.com', $user->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        // 2人目は作れない
        $this->artisan('user:create', ['--name' => 'x', '--email' => 'x@example.com'])->assertFailed();
        $this->assertSame(1, User::count());
    }

    public function test_create_user_rejects_short_or_mismatched_passwords(): void
    {
        $this->artisan('user:create', ['--name' => 'a', '--email' => 'a@example.com'])
            ->expectsQuestion('パスワード（12文字以上。入力しても表示されません）', 'short')
            ->assertFailed();
        $this->artisan('user:create', ['--name' => 'a', '--email' => 'a@example.com'])
            ->expectsQuestion('パスワード（12文字以上。入力しても表示されません）', self::PASSWORD)
            ->expectsQuestion('確認のため、もう一度入力してください', 'different-password')
            ->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_reset_password_command(): void
    {
        $user = $this->user();

        $this->artisan('user:password', ['--generate' => true])
            ->expectsOutputToContain('owner@example.com')          // だれのパスワードを変えるか表示してから
            ->expectsConfirmation('この人のパスワードを変えますか？', 'yes') // 確認する（追加した安全確認）
            ->expectsOutputToContain('この画面にだけ表示')
            ->assertSuccessful();

        $this->assertFalse(Hash::check(self::PASSWORD, $user->refresh()->password));
    }
}
