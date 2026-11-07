<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** 画面からのパスワードの変更（本人だけ・いまのパスワードを確かめる・ほかの端末のログインを切る） */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'old-password-of-a-1';

    private const NEW = 'new-password-of-a-2';

    private User $a;

    private User $b;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']); // 本番と同じ
        $this->a = User::factory()->create(['email' => 'a@example.com', 'password' => self::OLD]);
        $this->b = User::factory()->create(['email' => 'b@example.com', 'password' => 'password-of-b-123']);
    }

    // 17) パスワードの変更が正常に動く
    public function test_changes_own_password_and_logs_out_other_devices(): void
    {
        DB::table('sessions')->insert([
            ['id' => 'a-phone', 'user_id' => $this->a->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'b-phone', 'user_id' => $this->b->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $this->a->forceFill(['remember_token' => 'remember-a'])->save();

        $this->actingAs($this->a)->get('/settings/password')->assertOk()->assertSee('いまのパスワード');
        $this->put('/settings/password', ['current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertRedirect('/settings')->assertSessionHas('status');

        $fresh = $this->a->refresh();
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        $this->assertNotSame('remember-a', $fresh->remember_token);  // 「ログインしたままにする」も切る
        $this->assertSame(0, DB::table('sessions')->where('id', 'a-phone')->count()); // A のほかの端末
        $this->assertSame(1, DB::table('sessions')->where('id', 'b-phone')->count()); // B には影響しない
        $this->assertAuthenticatedAs($this->a); // この端末はそのまま使える

        $this->post('/logout');
        $this->post('/login', ['email' => 'a@example.com', 'password' => self::OLD])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'a@example.com', 'password' => self::NEW])->assertRedirect('/');
    }

    public function test_wrong_current_password_or_bad_new_password_changes_nothing(): void
    {
        $this->actingAs($this->a);
        $this->put('/settings/password', ['current_password' => 'wrong-password-x', 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertSessionHasErrors('current_password');
        $this->put('/settings/password', ['current_password' => self::OLD, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->put('/settings/password', ['current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => 'other-password-3'])
            ->assertSessionHasErrors('password');
        $this->put('/settings/password', ['current_password' => self::OLD, 'password' => self::OLD, 'password_confirmation' => self::OLD])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check(self::OLD, $this->a->refresh()->password));
    }

    // 18) 他人のパスワードは変えられない（ID やメールアドレスを送っても、変わるのはログイン中の本人だけ）
    public function test_cannot_change_someone_elses_password(): void
    {
        $bHash = $this->b->password;
        $this->actingAs($this->a)->put('/settings/password', [
            'current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW,
            'user_id' => $this->b->id, 'id' => $this->b->id, 'email' => 'b@example.com',
        ])->assertRedirect('/settings');

        $this->assertSame($bHash, $this->b->refresh()->password);
        $this->assertTrue(Hash::check(self::NEW, $this->a->refresh()->password));
        // B の「いまのパスワード」を知っていても、A のログインで B のパスワードは変えられない
        $this->put('/settings/password', ['current_password' => 'password-of-b-123', 'password' => 'hijack-password-9', 'password_confirmation' => 'hijack-password-9'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame($bHash, $this->b->refresh()->password);
    }

    public function test_password_change_is_rate_limited(): void
    {
        $this->actingAs($this->a);
        for ($i = 0; $i < 6; $i++) {
            $this->put('/settings/password', ['current_password' => "wrong-{$i}-password", 'password' => self::NEW, 'password_confirmation' => self::NEW]);
        }
        $this->put('/settings/password', ['current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertStatus(429);
        $this->assertTrue(Hash::check(self::OLD, $this->a->refresh()->password));
    }

    public function test_settings_page_links_to_password_change(): void
    {
        $this->actingAs($this->a)->get('/settings')->assertOk()->assertSee('パスワードを変える')->assertSee('a@example.com')->assertDontSee('b@example.com');
    }
}
