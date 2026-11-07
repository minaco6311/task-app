<?php

namespace App\Providers;

use App\Services\Line\LogMessenger;
use App\Services\Line\MessagingApiMessenger;
use App\Services\Line\Messenger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // LINEの送り方（.env の LINE_DRIVER）。標準は log（送らずに記録だけ）
        $this->app->bind(Messenger::class, fn () => config('line.driver') === 'line'
            ? new MessagingApiMessenger
            : new LogMessenger);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('e2e')) {
            // ブラウザテストは e2e/.runtime/ の SQLite 以外に接続させない（DB に触れる前に止める）
            $db = (string) config('database.connections.sqlite.database');
            if (config('database.default') !== 'sqlite' || ! str_starts_with($db, base_path('e2e/.runtime/'))) {
                throw new RuntimeException('E2E 環境は e2e/.runtime/ の SQLite 以外の DB に接続できません');
            }
            // E2E_NOW で時刻を固定する（夜間のスヌーズなどを確かめるため）。開発用・本番では APP_ENV が e2e にならないので動かない
            if ($fixed = env('E2E_NOW')) {
                $now = CarbonImmutable::parse($fixed, config('app.timezone'));
                Carbon::setTestNow($now);
                CarbonImmutable::setTestNow($now);
            }
        }

        // ログインの試行は、同じメールアドレス＋IPで1分に5回まで
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        // 招待リンクの確認・登録は、それぞれ同じIPから1分に10回まで（トークンの総当たりを防ぐ。トークンは256ビットの乱数）
        // ブラウザテストだけは、同じIPからたくさん試すので config/auth.php の invite_per_minute を増やす（本番は 10 のまま）
        RateLimiter::for('invite', fn (Request $request) => Limit::perMinute(config('auth.invite_per_minute'))
            ->by('invite|'.$request->path().'|'.$request->ip()));
    }
}
