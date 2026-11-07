#!/bin/sh
# ブラウザテスト（Playwright）の実行。プロジェクトのルートで `sh e2e/run.sh` と打つ
#   1) テスト専用の .env を e2e/.runtime/.env に作る（e2e/env.e2e ＋ ランダムな APP_KEY。Git 対象外）
#   2) 3つのシナリオそれぞれで安全確認（e2e/check.php）。1つでも失敗したら、テストを始めずに止まる
#   3) テストを実行し、終わったらテスト用コンテナを片付ける
# 開発用の MySQL・開発用の .env・LINE・Google には触れない（docker-compose.e2e.yml の説明も参照）
set -eu

cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.e2e.yml"
RUNTIME=e2e/.runtime

mkdir -p "$RUNTIME"

# 1) テスト専用の .env（以前のものは作り直す。画面には何も出さない）
umask 077
# （直接 up したときに Docker が .env をフォルダとして作ることがあるので、フォルダでも消す。消すのは e2e/.runtime/.env だけ）
rm -rf "$RUNTIME/.env"
{
    grep -v '^APP_KEY=' e2e/env.e2e
    printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
} > "$RUNTIME/.env"

# 念のため、作った .env がテスト専用の内容になっているかをここでも確かめる
grep -qx 'APP_ENV=e2e' "$RUNTIME/.env" || { echo "[e2e/run] テスト用 .env に APP_ENV=e2e がありません" >&2; exit 1; }
grep -qx 'DB_CONNECTION=sqlite' "$RUNTIME/.env" || { echo "[e2e/run] テスト用 .env が SQLite ではありません" >&2; exit 1; }

# 片付け（途中で止まっても必ず行う。テスト用のコンテナとネットワークだけ。開発用のコンテナ・データには触れない）
cleanup() {
    $COMPOSE down --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

# 2) 安全確認（DB を作る前。何も変更しない）
for scenario in day night limit; do
    $COMPOSE run --rm --no-deps -T "app-$scenario" php e2e/check.php
done

# 3) テスト
$COMPOSE up --build --abort-on-container-exit --exit-code-from playwright
