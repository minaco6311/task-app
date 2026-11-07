<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Invitation;
use App\Models\Task;
use App\Models\User;
use App\Services\Registration;
use App\Support\Owner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** 招待制のアカウント登録（招待リンクを持つ人だけ・1回だけ・7日間・招待先のメールアドレスだけ） */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'new-member-password-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));
    }

    /** @return array{0: string, 1: Invitation} トークンと招待 */
    private function invite(string $email = 'friend@example.com', ?\DateTimeInterface $expires = null): array
    {
        $token = Invitation::newToken();
        $inv = new Invitation;
        $inv->forceFill(['email' => $email, 'token_hash' => Invitation::hashToken($token), 'expires_at' => $expires ?? now()->addDays(7)])->save();

        return [$token, $inv];
    }

    private function form(array $over = []): array
    {
        return ['name' => '友だち', 'email' => 'friend@example.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, ...$over];
    }

    // 1) 招待なしでは登録できない
    public function test_cannot_register_without_an_invitation(): void
    {
        $this->get('/register')->assertNotFound()->assertSee('招待リンクを開いてから登録してください');
        $this->post('/register', $this->form())->assertNotFound();
        $this->post('/invite', ['token' => str_repeat('a', 64)])->assertNotFound()->assertSee('この招待リンクは使えません');
        $this->post('/invite', ['token' => 'short'])->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('新規登録')->assertSee('招待制');
        $this->assertSame(0, User::count());
    }

    // 2) 正しい招待リンクでは登録できる（ログインした状態になり、自分専用の初期状態から始まる）
    public function test_registers_with_a_valid_invitation(): void
    {
        [$token, $inv] = $this->invite();

        $this->get('/invite')->assertOk()->assertSee('data-invite-form', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $this->get('/invite')->headers->get('Cache-Control'));
        // トークンは POST で送る → 登録画面へ（URL にトークンは入らない）
        $res = $this->post('/invite', ['token' => strtoupper($token)]);
        $res->assertRedirect('/register')->assertStatus(303);
        $this->assertStringNotContainsString($token, (string) $res->headers->get('Location'));
        $this->get('/register')->assertOk()->assertSee('fr•••@example.com')->assertDontSee($token);

        $this->post('/register', $this->form(['email' => ' Friend@Example.com ']))->assertRedirect('/');

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('friend@example.com', $user->email);
        $this->assertSame('友だち', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        $inv->refresh();
        $this->assertNotNull($inv->used_at);
        $this->assertSame($user->id, $inv->used_by);
        $this->assertSame('used', $inv->state());
        $this->assertNull(session('invitation'));
        // 初期状態：自分の凡例（6つ）だけ。予定はない
        $this->assertSame(6, Owner::run($user, fn () => Category::count()));
        $this->assertSame(0, Owner::run($user, fn () => Task::count()));
        $this->get('/')->assertOk();
    }

    // 3) 期限切れのリンクでは登録できない（期限切れの画面）
    public function test_expired_invitation_cannot_be_used(): void
    {
        [$token] = $this->invite(expires: now()->addMinute());
        $this->travel(2)->minutes();

        $this->post('/invite', ['token' => $token])->assertStatus(410)->assertSee('招待リンクの期限が切れています');
        $this->post('/register', $this->form())->assertNotFound();
        $this->assertSame(0, User::count());
    }

    public function test_invitation_expiring_after_it_was_opened_cannot_be_used(): void
    {
        [$token] = $this->invite(expires: now()->addMinutes(5));
        $this->post('/invite', ['token' => $token])->assertRedirect('/register');
        $this->travel(6)->minutes(); // 登録画面を開いたまま期限が切れた

        $this->post('/register', $this->form())->assertStatus(410)->assertSee('期限が切れています');
        $this->assertSame(0, User::count());
    }

    // 4) 利用済みのリンクは再利用できない
    public function test_used_invitation_cannot_be_reused(): void
    {
        [$token] = $this->invite();
        $this->post('/invite', ['token' => $token]);
        $this->post('/register', $this->form())->assertRedirect('/');
        $this->post('/logout');

        $this->post('/invite', ['token' => $token])->assertNotFound()->assertSee('すでに使われています');
        $this->post('/register', $this->form(['name' => '2人目']))->assertNotFound();
        $this->assertSame(1, User::count());
    }

    // 5) 無効化したリンクでは登録できない
    public function test_revoked_invitation_cannot_be_used(): void
    {
        [$token, $inv] = $this->invite();
        $this->post('/invite', ['token' => $token])->assertRedirect('/register');
        $this->artisan('invite:revoke', ['id' => $inv->id, '--force' => true])->assertSuccessful();

        $this->post('/register', $this->form())->assertNotFound()->assertSee('使えなくなりました');
        $this->post('/invite', ['token' => $token])->assertNotFound()->assertSee('無効になっています');
        $this->assertSame(0, User::count());
    }

    // 6) 招待先と違うメールアドレスでは登録できない
    public function test_email_must_match_the_invitation(): void
    {
        [$token, $inv] = $this->invite();
        $this->post('/invite', ['token' => $token]);

        $this->post('/register', $this->form(['email' => 'someone-else@example.com']))->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());
        $this->assertSame('usable', $inv->refresh()->state()); // 招待は使われずに残る
    }

    public function test_registration_validates_name_and_password(): void
    {
        [$token] = $this->invite();
        $this->post('/invite', ['token' => $token]);

        $this->post('/register', $this->form(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/register', $this->form(['password' => 'short-pass', 'password_confirmation' => 'short-pass']))->assertSessionHasErrors('password');
        $this->post('/register', $this->form(['password_confirmation' => 'different-password-1']))->assertSessionHasErrors('password');
        $this->post('/register', $this->form(['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)]))->assertSessionHasErrors('password');
        $this->assertSame(0, User::count());
        // 入力エラーで戻っても、パスワードはセッションに残さない
        $this->assertNull(session()->getOldInput('password'));
    }

    // 7) 同じリンクで続けて登録しても、アカウントは1つだけ（本当に同時の場合は InvitationConcurrencyTest）
    public function test_the_same_invitation_creates_only_one_account(): void
    {
        [$token, $inv] = $this->invite();
        $this->post('/invite', ['token' => $token]);
        $stale = session('invitation'); // 別のタブで、同じ招待を確認したまま開いている
        $this->post('/register', $this->form())->assertRedirect('/');
        $this->post('/logout');

        // 先に登録した人がメールアドレスを変えていても、招待はもう使えない
        User::query()->sole()->forceFill(['email' => 'changed@example.com'])->save();
        $this->withSession(['invitation' => $stale])->post('/register', $this->form(['name' => 'もう1人']))->assertNotFound();
        $this->assertSame(1, User::count());

        // 処理そのものも、2回目は何も作らない（使えない招待・同じメールアドレス）
        $this->assertSame(Registration::UNAVAILABLE, app(Registration::class)->register($inv, 'x', 'friend@example.com', self::PASSWORD));
        $this->assertSame(1, User::count());
    }

    public function test_invitation_for_an_existing_email_does_not_create_a_second_account(): void
    {
        User::factory()->create(['email' => 'friend@example.com']);
        [$token, $inv] = $this->invite();
        $this->post('/invite', ['token' => $token]);

        $this->post('/register', $this->form())->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
        $this->assertSame('usable', $inv->refresh()->state());
    }

    public function test_only_the_token_hash_is_stored_and_tokens_are_random(): void
    {
        [$token, $inv] = $this->invite();
        $this->assertMatchesRegularExpression(Invitation::TOKEN_PATTERN, $token);
        $this->assertSame(hash('sha256', $token), $inv->refresh()->token_hash);
        $this->assertNotSame($token, $inv->token_hash);
        $this->assertNotSame(Invitation::newToken(), Invitation::newToken());
        $this->assertNull(Invitation::findByToken(strtoupper($token))); // 形が違うものは探さない（画面では小文字にそろえてから探す）
        $this->assertTrue(Invitation::findByToken($token)->is($inv));
    }

    public function test_invite_form_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/invite', ['token' => str_repeat('b', 64)])->assertNotFound();
        }
        $this->post('/invite', ['token' => str_repeat('b', 64)])->assertStatus(429);
    }

    public function test_logged_in_users_do_not_see_the_invitation_pages(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/invite')->assertRedirect('/');
        $this->get('/register')->assertRedirect('/');
    }

    public function test_session_id_changes_when_the_invitation_is_accepted_and_on_registration(): void
    {
        [$token] = $this->invite();
        $this->get('/invite');
        $before = session()->getId();
        $this->post('/invite', ['token' => $token]);
        $afterRedeem = session()->getId();
        $this->assertNotSame($before, $afterRedeem);
        $this->post('/register', $this->form());
        $this->assertNotSame($afterRedeem, session()->getId());
    }
}
