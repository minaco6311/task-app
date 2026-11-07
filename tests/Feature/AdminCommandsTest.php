<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** 管理用コマンド（招待の発行・一覧・無効化、ユーザーを1人に決めてからの変更） */
class AdminCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const ASK_PASSWORD = 'パスワード（12文字以上。入力しても表示されません）';

    private const ASK_AGAIN = '確認のため、もう一度入力してください';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));
        config(['app.url' => 'https://irodori.example.test']);
    }

    public function test_invite_create_shows_the_link_once_and_stores_only_the_hash(): void
    {
        Artisan::call('invite:create', ['--email' => 'Friend@Example.com']);
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('~https://irodori\.example\.test/invite#[0-9a-f]{64}~', $out);
        preg_match('~/invite#([0-9a-f]{64})~', $out, $m);

        $inv = Invitation::query()->sole();
        $this->assertSame('friend@example.com', $inv->email);
        $this->assertSame(hash('sha256', $m[1]), $inv->token_hash);
        $this->assertSame('2026-11-12 10:00', $inv->expires_at->format('Y-m-d H:i')); // 7日間
        $this->assertSame('usable', $inv->state());
        // DB のどこにもトークンそのものは残らない
        $this->assertStringNotContainsString($m[1], json_encode(Invitation::query()->get()->toArray()));
        // 一覧にも表示されない
        Artisan::call('invite:list');
        $this->assertStringNotContainsString($m[1], Artisan::output());
    }

    public function test_invite_create_refuses_existing_users_and_duplicate_invitations(): void
    {
        User::factory()->create(['email' => 'member@example.com']);
        $this->artisan('invite:create', ['--email' => 'member@example.com'])->assertFailed();
        $this->artisan('invite:create', ['--email' => 'not-an-email'])->assertFailed();
        $this->artisan('invite:create', ['--email' => 'new@example.com'])->assertSuccessful();
        $this->artisan('invite:create', ['--email' => 'new@example.com'])->expectsOutputToContain('まだ使える招待があります')->assertFailed();
        $this->assertSame(1, Invitation::count());
    }

    public function test_invite_list_and_revoke(): void
    {
        $this->artisan('invite:create', ['--email' => 'one@example.com'])->assertSuccessful();
        $this->artisan('invite:create', ['--email' => 'two@example.com'])->assertSuccessful();
        [$one, $two] = Invitation::query()->orderBy('id')->get()->all();

        $this->artisan('invite:revoke', ['id' => $one->id])
            ->expectsConfirmation("招待 {$one->id}（one@example.com・未使用）を無効にしますか？", 'no')->assertFailed();
        $this->assertSame('usable', $one->refresh()->state());
        $this->artisan('invite:revoke', ['id' => $one->id])
            ->expectsConfirmation("招待 {$one->id}（one@example.com・未使用）を無効にしますか？", 'yes')->assertSuccessful();
        $this->assertSame('revoked', $one->refresh()->state());
        $this->artisan('invite:revoke', ['id' => $one->id, '--force' => true])->assertFailed(); // 2回目は不要
        $this->artisan('invite:revoke', ['id' => 999, '--force' => true])->assertFailed();

        $this->travel(8)->days();
        Artisan::call('invite:list');
        $out = Artisan::output();
        $this->assertStringContainsString('無効化', $out);
        $this->assertStringContainsString('期限切れ', $out);
        $this->assertSame('expired', $two->refresh()->state());
    }

    public function test_user_password_needs_a_target_when_there_are_several_users(): void
    {
        $a = User::factory()->create(['email' => 'a@example.com']);
        $b = User::factory()->create(['email' => 'b@example.com']);
        $bHash = $b->password;

        // --user がないと、だれも変えない（勝手に1人目を選ばない）
        $this->artisan('user:password')->expectsOutputToContain('ユーザーが 2 人います')->assertFailed();
        $this->artisan('user:password', ['--user' => 'nobody@example.com'])->assertFailed();

        $this->artisan('user:password', ['--user' => 'A@Example.com'])
            ->expectsOutputToContain("ID {$a->id}")
            ->expectsConfirmation('この人のパスワードを変えますか？', 'yes')
            ->expectsQuestion(self::ASK_PASSWORD, 'reset-password-for-a')
            ->expectsQuestion(self::ASK_AGAIN, 'reset-password-for-a')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('reset-password-for-a', $a->refresh()->password));
        $this->assertSame($bHash, $b->refresh()->password);

        // ID でも指定できる。確認で「いいえ」なら変えない
        $this->artisan('user:password', ['--user' => (string) $b->id])
            ->expectsConfirmation('この人のパスワードを変えますか？', 'no')->assertFailed();
        $this->assertSame($bHash, $b->refresh()->password);
    }

    public function test_user_credentials_needs_a_target_when_there_are_several_users(): void
    {
        $a = User::factory()->create(['email' => 'a@example.com']);
        $b = User::factory()->create(['email' => 'b@example.com']);

        $this->artisan('user:credentials', ['--email' => 'x@example.com'])->expectsOutputToContain('ユーザーが 2 人います')->assertFailed();
        // ほかの人と同じメールアドレスにはできない
        $this->artisan('user:credentials', ['--user' => 'a@example.com', '--email' => 'b@example.com'])->assertFailed();

        $this->artisan('user:credentials', ['--user' => 'b@example.com', '--email' => 'b2@example.com'])
            ->expectsOutputToContain("ID {$b->id} の人")
            ->expectsConfirmation('この内容で変更しますか？', 'yes')
            ->assertSuccessful();
        $this->assertSame('b2@example.com', $b->refresh()->email);
        $this->assertSame('a@example.com', $a->refresh()->email);
        $this->assertSame(2, User::count());
    }

    public function test_user_create_is_only_for_the_first_user_and_user_list_shows_users(): void
    {
        User::factory()->create(['name' => '最初の人', 'email' => 'first@example.com']);
        $this->artisan('user:create', ['--name' => 'b', '--email' => 'b@example.com'])->expectsOutputToContain('invite:create')->assertFailed();
        $this->assertSame(1, User::count());

        Artisan::call('user:list');
        $out = Artisan::output();
        $this->assertStringContainsString('first@example.com', $out);
        $this->assertStringContainsString('最初の人', $out);
    }
}
