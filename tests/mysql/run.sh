#!/bin/sh
# PHP のテスト（tests/Feature・tests/Unit）を、使い捨てのテスト用 MySQL 8.4 で実行する。プロジェクトのルートで `sh tests/mysql/run.sh`
#   追加の引数は phpunit にそのまま渡す（例：sh tests/mysql/run.sh --filter=MultiUser）
#
#   1) テスト専用の .env を tests/mysql/.runtime/.env に作る（tests/mysql/env.mysqltest ＋ ランダムな APP_KEY。Git 対象外）
#   2) 安全確認：コンテナから見える .env・接続先がテスト用（irodori_test @ mysqltest-db）か。違えば何もせずに止まる
#   3) テストを実行し、終わったらコンテナ・ネットワークを消す（MySQL はメモリ上なので、データは残らない）
#
# 開発用の MySQL（task_app）・本番（AWS）・開発用の .env には触れない。SQLite にも切り替えない
set -eu

cd "$(dirname "$0")/../.."
COMPOSE="docker compose -f tests/mysql/compose.yml"
RT=tests/mysql/.runtime

umask 077
mkdir -p "$RT"
rm -rf "$RT/.env"
{
    grep -v '^APP_KEY=' tests/mysql/env.mysqltest
    printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
} > "$RT/.env"

cleanup() {
    $COMPOSE down --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

# 2) 安全確認（テストを始める前。DB は作り直さない）
$COMPOSE run --rm -T php php -r '
    $env = @file_get_contents("/var/www/html/.env") ?: "";
    $ok = preg_match("/^DB_CONNECTION=mysql$/m", $env) && preg_match("/^DB_HOST=mysqltest-db$/m", $env)
        && preg_match("/^DB_DATABASE=irodori_test$/m", $env) && preg_match("/^APP_ENV=testing$/m", $env)
        && preg_match("/^LINE_DRIVER=log$/m", $env);
    if (! $ok) { fwrite(STDERR, "[mysqltest] コンテナ内の .env がテスト用ではありません。中止します\n"); exit(1); }
    $ip = gethostbyname("db");
    if ($ip !== "db") { fwrite(STDERR, "[mysqltest] 開発用の MySQL（db）に届くネットワークです。中止します\n"); exit(1); }
    $pdo = new PDO("mysql:host=mysqltest-db;port=3306;dbname=irodori_test", "irodori_test", "irodori_test_only");
    $v = $pdo->query("select version()")->fetchColumn();
    echo "[mysqltest] 接続先：irodori_test @ mysqltest-db（MySQL {$v}）\n";
'

# 3) テスト
$COMPOSE run --rm -T php php vendor/bin/phpunit -c tests/mysql/phpunit.xml "$@"
