<?php

/**
 * ブラウザテスト（e2e）の環境の安全確認。1つでも合わなければ、理由を出して終了コード 1 で止まる
 *   ・e2e/setup.php の最初に読み込む（テスト用 DB を作る前に確かめる）
 *   ・e2e/run.sh が、テストを始める前に単独でも実行する（何も変更しない）
 *
 * 確かめること
 *   ・コンテナ内の .env がテスト専用のもの（開発用の .env が見えていない）
 *   ・APP_ENV=e2e、DB_CONNECTION=sqlite、DB_DATABASE が e2e/.runtime/ の中、MySQL の接続先が開発用（db）でない
 *   ・Laravel が読み込んだ設定も同じ（環境変数が渡らなかった場合に気づく）
 *   ・LINE は log（送らない）。line を許すのは上限の画面を見るシナリオ（limit）だけで、そのときも LINE のトークンは無い
 */

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__);
$runtime = $root.'/e2e/.runtime/';
$scenario = (string) getenv('E2E_SCENARIO');
$problems = [];

// 1) コンテナ内の .env がテスト専用か（開発用の .env には APP_ENV=e2e が無い）
$dotenv = @file_get_contents($root.'/.env') ?: '';
if (! preg_match('/^APP_ENV=e2e$/m', $dotenv) || ! preg_match('/^DB_CONNECTION=sqlite$/m', $dotenv)) {
    $problems[] = 'コンテナ内の .env がテスト専用ではありません（開発用の .env が見えています）';
}
if (! preg_match('/^APP_KEY=base64:.{40,}$/m', $dotenv)) {
    $problems[] = 'テスト用の APP_KEY がありません（e2e/run.sh から実行してください）';
}

// 2) 環境変数
$env = [
    'APP_ENV' => [fn ($v) => $v === 'e2e', 'e2e ではありません'],
    'DB_CONNECTION' => [fn ($v) => $v === 'sqlite', 'sqlite ではありません'],
    'DB_DATABASE' => [fn ($v) => str_starts_with($v, $runtime) && str_ends_with($v, '.sqlite'), 'e2e/.runtime/ の SQLite ファイルではありません'],
    'DB_HOST' => [fn ($v) => ! in_array($v, ['', 'db', '127.0.0.1', 'localhost'], true), '開発用の MySQL を指しています'],
    'E2E_SCENARIO' => [fn ($v) => in_array($v, ['day', 'night', 'limit'], true), 'day / night / limit のどれでもありません'],
    'LINE_DRIVER' => [fn ($v) => $v === 'log' || ($v === 'line' && $scenario === 'limit'), 'log ではありません（line は limit だけ）'],
];
foreach ($env as $key => [$ok, $why]) {
    if (! $ok((string) getenv($key))) {
        $problems[] = "{$key}: {$why}";
    }
}

if ($problems) {
    fwrite(STDERR, "[e2e/check] 安全確認に失敗しました。テストを始めません：\n  - ".implode("\n  - ", $problems)."\n");
    exit(1);
}

// 3) Laravel が実際に読み込んだ設定（DB にはまだ接続しない）
require_once $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = (string) config('database.connections.sqlite.database');
$checks = [
    'Laravel の APP_ENV が e2e' => app()->environment('e2e'),
    'Laravel の DB が sqlite' => config('database.default') === 'sqlite',
    'Laravel の SQLite が e2e/.runtime/ の中' => $db === getenv('DB_DATABASE') && str_starts_with($db, $runtime),
    'Laravel の MySQL の接続先が開発用でない' => ! in_array(config('database.connections.mysql.host'), ['db', '127.0.0.1', 'localhost'], true),
    'Laravel の LINE の送り方' => config('line.driver') === 'log' || (config('line.driver') === 'line' && $scenario === 'limit'),
    '設定のキャッシュを使っていない' => ! app()->configurationIsCached(),
];
$failed = array_keys(array_filter($checks, fn ($ok) => ! $ok));
if ($failed) {
    fwrite(STDERR, "[e2e/check] 安全確認に失敗しました。テストを始めません：\n  - ".implode("\n  - ", $failed)."\n");
    exit(1);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    fwrite(STDOUT, "[e2e/check] {$scenario}: OK（{$db}・LINE=".config('line.driver')."）\n");
}
