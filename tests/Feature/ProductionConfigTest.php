<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Line\LogMessenger;
use App\Services\Line\MessagingApiMessenger;
use App\Services\Line\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 本番（CloudFront → EC2 の nginx → Laravel）の設定
 *   ・nginx が HTTPS=on を渡したときだけ https の URL と Secure の Cookie になる（開発用の HTTP はそのまま）
 *   ・利用者が送った X-Forwarded-* は信用しない（trustProxies を使っていない）
 *   ・本番用 Compose は LINE を log に固定し、開発用と取り違えない設定になっている
 * nginx を含めた実際の動きは e2e/prodcheck/run.sh で確かめる
 */
class ProductionConfigTest extends TestCase
{
    use RefreshDatabase;

    // https:// の URL で呼ぶと、nginx が渡すのと同じ HTTPS=on・SERVER_PORT=443・HTTP_HOST になる
    private const BASE = 'https://irodori.example.com';

    public function test_https_from_nginx_makes_https_urls_and_secure_cookies(): void
    {
        config(['session.secure' => true]);

        $res = $this->get(self::BASE.'/login')->assertOk();
        $res->assertSee('action="'.self::BASE.'/login"', false);
        $this->assertTrue($res->getCookie(config('session.cookie'), false)->isSecure());
        $this->assertTrue($res->getCookie('XSRF-TOKEN', false)->isSecure());
        $this->assertTrue($res->getCookie(config('session.cookie'), false)->isHttpOnly());

        // ログインしていないときの移動先も https
        $this->get(self::BASE.'/')->assertRedirect(self::BASE.'/login');
    }

    public function test_login_redirects_to_https(): void
    {
        config(['session.secure' => true]);
        User::factory()->create(['email' => 'owner@example.com', 'password' => 'correct-horse-battery']);

        $this->post(self::BASE.'/login', ['email' => 'owner@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect(self::BASE);
        $this->assertAuthenticated();
    }

    public function test_local_http_stays_http_without_secure_cookies(): void
    {
        config(['session.secure' => null]);

        $res = $this->get('/login')->assertOk();
        $res->assertSee('action="http://localhost', false)->assertDontSee('action="https://', false);
        $this->assertFalse($res->getCookie(config('session.cookie'), false)->isSecure());
    }

    public function test_forwarded_headers_from_the_client_are_not_trusted(): void
    {
        $res = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Port' => '8443',
            'X-Forwarded-For' => '203.0.113.9',
        ])->get('/login')->assertOk();

        $res->assertSee('action="http://localhost', false)->assertDontSee('action="https://', false);
        $res->assertDontSee('evil.example');
        $this->assertSame('127.0.0.1', request()->ip());
    }

    public function test_only_the_exact_value_line_selects_the_real_line_sender(): void
    {
        foreach (['log' => LogMessenger::class, 'line' => MessagingApiMessenger::class] as $driver => $class) {
            config(['line.driver' => $driver]);
            $this->assertInstanceOf($class, app(Messenger::class));
        }

        // config/line.php：「line」以外（大文字・空白入り・空・未設定）はすべて log
        foreach (['LINE', ' line', 'Line', '', 'true', null] as $value) {
            $this->assertSame('log', $this->lineDriverFor($value), var_export($value, true));
        }
        $this->assertSame('line', $this->lineDriverFor('line'));
    }

    public function test_production_compose_forces_line_driver_and_production_settings(): void
    {
        $yaml = file_get_contents(base_path('docker-compose.prod.yml'));

        $this->assertMatchesRegularExpression('/^name: irodori-prod$/m', $yaml);
        foreach (['app', 'scheduler'] as $service) {
            $block = $this->service($yaml, $service);
            $this->assertStringContainsString('build: *php-build', $block, $service);
            foreach (['APP_ENV: production', 'APP_DEBUG: "false"', 'SESSION_SECURE_COOKIE: "true"', 'LINE_DRIVER: line'] as $line) {
                $this->assertStringContainsString($line, $block, "{$service} に {$line} がない");
            }
        }
        $this->assertMatchesRegularExpression('/x-php-build:.*?target: prod/s', $yaml);

        $web = $this->service($yaml, 'web');
        $this->assertStringContainsString('./docker/nginx/prod.conf.template:/etc/nginx/templates/default.conf.template:ro', $web);
        $this->assertStringNotContainsString('default.conf:/etc/nginx/conf.d', $web);
        $this->assertStringContainsString('- ./.env.nginx', $web);
        $this->assertStringContainsString('NGINX_ENVSUBST_FILTER: "^(APP_DOMAIN|ORIGIN_VERIFY_SECRET)$$"', $web);

        // MySQL は外に公開しない
        $this->assertStringNotContainsString('ports:', $this->service($yaml, 'db'));
    }

    public function test_production_nginx_template_checks_host_and_secret_header(): void
    {
        $conf = file_get_contents(base_path('docker/nginx/prod.conf.template'));
        $code = preg_replace('/^\s*#.*$/m', '', $conf);

        // 置き換えるのは2つだけ。秘密の値そのものは書かない
        preg_match_all('/\$\{([A-Za-z_]+)\}/', $code, $m);
        $this->assertSame(['APP_DOMAIN', 'ORIGIN_VERIFY_SECRET'], array_values(array_unique($m[1])));

        $this->assertMatchesRegularExpression('/listen 80 default_server;\s*server_name _;\s*return 444;/', $code);
        $this->assertStringContainsString('if ($http_x_origin_verify = "")', $code);
        $this->assertStringContainsString('if ($http_x_origin_verify != "${ORIGIN_VERIFY_SECRET}")', $code);
        $this->assertStringNotContainsString('include fastcgi_params', $code);

        // FastCGI のパラメーターは同じ名前を2回書かない
        preg_match_all('/fastcgi_param\s+(\S+)/', $code, $p);
        $this->assertSame(array_unique($p[1]), $p[1]);
        foreach (['HTTPS              on', 'REQUEST_SCHEME     https', 'SERVER_PORT        443'] as $param) {
            $this->assertStringContainsString("fastcgi_param {$param};", $code);
        }
        foreach (['X_ORIGIN_VERIFY', 'X_FORWARDED_FOR', 'X_FORWARDED_HOST', 'X_FORWARDED_PROTO', 'X_FORWARDED_PORT', 'X_FORWARDED_PREFIX'] as $h) {
            $this->assertMatchesRegularExpression("/fastcgi_param HTTP_{$h}\s+\"\" if_not_empty;/", $code);
        }
    }

    /** config/line.php を LINE_DRIVER = $value として読み直したときの driver */
    private function lineDriverFor(?string $value): string
    {
        $before = $_ENV['LINE_DRIVER'] ?? null;
        $restore = fn () => $before === null ? $this->unsetEnv() : $this->setEnv($before);
        $value === null ? $this->unsetEnv() : $this->setEnv($value);
        try {
            return (require config_path('line.php'))['driver'];
        } finally {
            $restore();
        }
    }

    private function setEnv(string $value): void
    {
        $_ENV['LINE_DRIVER'] = $_SERVER['LINE_DRIVER'] = $value;
        putenv("LINE_DRIVER={$value}");
    }

    private function unsetEnv(): void
    {
        unset($_ENV['LINE_DRIVER'], $_SERVER['LINE_DRIVER']);
        putenv('LINE_DRIVER');
    }

    /** docker-compose.prod.yml の1つのサービスの部分 */
    private function service(string $yaml, string $name): string
    {
        $this->assertMatchesRegularExpression("/^  {$name}:$/m", $yaml, "{$name} がない");
        preg_match("/^  {$name}:\n(.*?)(?=^  [a-z][a-z-]*:$|^[a-z]|\z)/ms", $yaml, $m);

        return $m[1];
    }
}
