<?php

namespace Tests\Feature;

use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** LINE連携の画面（トークンを画面・セッション・DBの平文に出さない） */
class LineSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'secret-channel-access-token-ZZZZ-0123456789wxyz';

    private const USER_ID = 'U0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_guide_page_works_without_line(): void
    {
        $this->get('/settings/line')->assertOk()->assertSee('未連携')->assertSee('設定ガイド')
            ->assertSee('あなたのユーザーID')->assertSee('シミュレーション');
        $this->get('/settings')->assertOk()->assertSee('LINEを連携する');
        $this->get('/')->assertOk();
    }

    public function test_saving_encrypts_and_never_shows_the_token(): void
    {
        $this->put('/settings/line', ['channel_access_token' => ' '.self::TOKEN."\n", 'line_user_id' => self::USER_ID])
            ->assertRedirect('/settings/line');

        $raw = DB::table('line_connections')->first();
        $this->assertNotSame(self::TOKEN, $raw->channel_access_token);
        $this->assertStringNotContainsString(self::TOKEN, $raw->channel_access_token);
        $this->assertStringNotContainsString(self::USER_ID, $raw->line_user_id);
        $this->assertSame(self::TOKEN, LineConnection::current()->channel_access_token);
        $this->assertStringNotContainsString(self::TOKEN, LineConnection::current()->toJson());

        $this->get('/settings/line')->assertOk()
            ->assertDontSee(self::TOKEN)->assertDontSee(self::USER_ID)
            ->assertSee('wxyz')->assertSee('テスト送信');

        // 空欄で保存しても、いまのトークンが消えない
        $this->put('/settings/line', ['channel_access_token' => '', 'line_user_id' => ''])->assertSessionHas('status');
        $this->assertSame(self::TOKEN, LineConnection::current()->channel_access_token);
    }

    public function test_validation_errors_do_not_keep_the_token_in_the_session(): void
    {
        $this->from('/settings/line')
            ->put('/settings/line', ['channel_access_token' => self::TOKEN, 'line_user_id' => '1234567890'])
            ->assertRedirect('/settings/line')
            ->assertSessionHasErrors('line_user_id');

        $this->assertNull(session()->getOldInput('channel_access_token'));
        $this->assertSame(0, LineConnection::count());
    }

    public function test_test_send_is_simulated_with_log_driver(): void
    {
        LineConnection::query()->create(['channel_access_token' => self::TOKEN, 'line_user_id' => self::USER_ID]);

        $this->post('/settings/line/test')->assertSessionHas('status', fn ($s) => str_contains($s, 'シミュレーション'));

        Http::assertNothingSent();
        $this->assertSame('simulated', NotificationLog::query()->where('type', 'test')->value('status'));
    }

    public function test_test_send_with_line_driver(): void
    {
        config(['line.driver' => 'line']);
        LineConnection::query()->create(['channel_access_token' => self::TOKEN, 'line_user_id' => self::USER_ID]);
        Http::fake(['api.line.me/*' => Http::sequence()->push([])->push(['message' => 'Authentication failed'], 401)]);

        $this->post('/settings/line/test')->assertSessionHas('status', fn ($s) => str_contains($s, 'テスト送信しました'));
        $this->assertSame('ok', LineConnection::current()->status);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Line-Retry-Key') && $r['to'] === self::USER_ID);

        $this->post('/settings/line/test')->assertSessionHas('status', fn ($s) => str_contains($s, '401') && ! str_contains($s, self::TOKEN));
        $this->assertSame('error', LineConnection::current()->status);
        $this->get('/settings/line')->assertSee('再設定が必要')->assertDontSee(self::TOKEN);
    }

    public function test_disconnect(): void
    {
        LineConnection::query()->create(['channel_access_token' => self::TOKEN, 'line_user_id' => self::USER_ID]);

        $this->delete('/settings/line')->assertRedirect('/settings/line');

        $this->assertSame(0, LineConnection::count());
    }

    public function test_reset_page_explains_what_is_deleted(): void
    {
        $this->get('/settings')->assertOk()
            ->assertSee('で消えるもの')->assertSee('消えずに残るもの')->assertSee('ログイン情報')->assertSee('通知履歴');
    }
}
