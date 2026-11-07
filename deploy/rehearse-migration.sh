#!/usr/bin/env bash
# 複数ユーザー化のマイグレーションの「リハーサル」：バックアップを使い捨ての MySQL に戻して、マイグレーションを試す
#   ./deploy/rehearse-migration.sh ~/irodori-backups/irodori-YYYYMMDD-HHMMSS.sql.gz
#
# ・本番の DB・コンテナ・ボリュームには一切触れない
#   - MySQL は新しく起動する使い捨てのコンテナ（データはメモリ上・終わったら消える）
#   - ネットワークも使い捨て（internal = 外にも本番の db にもつながらない）
#   - アプリのコードは、いまのフォルダ（git で新しい版にしたもの）を読み取り専用で使う
#   - Laravel の設定のキャッシュ（bootstrap/cache/config.php）は読まない（本番の接続先が入っているため）
#     bootstrap/cache は使い捨ての場所（tmpfs）にする（新しく用意したフォルダでも動くように・本番のキャッシュに触れないように）
# ・前後で各テーブルの件数を比べ、持ち主（user_id）がちょうど1人に決まったかを確かめる
# ・止まった場合（ユーザーが0人・2人以上など）も、その理由を表示する。本番ではその状態で本番の移行をしない
#
# 使うイメージ：APP_IMAGE（標準は本番の app のイメージ irodori-prod-app。docker compose ... build app で新しいコードで作っておく）
set -euo pipefail

BACKUP="${1:-}"
APP_IMAGE="${APP_IMAGE:-irodori-prod-app}"
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.4}"
log() { printf '[rehearsal] %s\n' "$*"; }
fail() { printf '[rehearsal] 中止：%s\n' "$*" >&2; exit 1; }

[ -n "$BACKUP" ] || fail "バックアップのファイルを指定してください（例：./deploy/rehearse-migration.sh ~/irodori-backups/irodori-…sql.gz）"
[ -f "$BACKUP" ] || fail "ファイルがありません：$BACKUP"
gzip -t "$BACKUP" || fail "バックアップが壊れています：$BACKUP"
gzip -dc "$BACKUP" | tail -n 1 | grep -q '^-- Dump completed' || fail "バックアップが最後まで保存されていません：$BACKUP"
docker image inspect "$APP_IMAGE" >/dev/null 2>&1 || fail "アプリのイメージ $APP_IMAGE がありません（先に build してください）"
[ -f artisan ] && [ -d vendor ] || fail "リポジトリのフォルダ（artisan と vendor/ がある所）で実行してください"

ID=$(openssl rand -hex 4)
NET="irodori-rehearsal-$ID"
DB="irodori-rehearsal-db-$ID"
PW=$(openssl rand -hex 16) # 使い捨ての DB のためだけのパスワード（終わったら DB ごと消える）

cleanup() {
    docker rm -f "$DB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

log "使い捨ての MySQL を起動します（${DB}・ネットワーク $NET は外につながりません）"
docker network create --internal "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" --tmpfs /var/lib/mysql \
    -e MYSQL_ROOT_PASSWORD="$PW" -e MYSQL_DATABASE=irodori -e TZ=Asia/Tokyo \
    --label irodori.rehearsal=1 "$MYSQL_IMAGE" >/dev/null
for _ in $(seq 1 90); do
    if docker exec "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "select 1" irodori' >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
docker exec "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "select 1" irodori' >/dev/null 2>&1 || fail "MySQL が起動しませんでした"

log "バックアップを戻します：$BACKUP"
gzip -dc "$BACKUP" | docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot irodori'

sql() { docker exec "$DB" sh -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot -N -B irodori -e \"$1\""; }
TABLES="users categories tasks subtasks task_series task_series_skips task_reminders notification_logs line_connections settings"
counts() {
    for t in $TABLES; do
        printf '%s=%s ' "$t" "$(sql "select count(*) from $t" 2>/dev/null || echo '-')"
    done
}

BEFORE=$(counts)
log "戻した直後の件数：$BEFORE"
log "まだ流していないマイグレーション："
docker run --rm --network "$NET" -v "$PWD":/var/www/html:ro --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777 -w /var/www/html \
    -e APP_ENV=production -e APP_CONFIG_CACHE=/tmp/no-config-cache.php -e APP_EVENTS_CACHE=/tmp/no-events-cache.php \
    -e LOG_CHANNEL=stderr -e DB_CONNECTION=mysql -e DB_HOST="$DB" -e DB_PORT=3306 -e DB_DATABASE=irodori \
    -e DB_USERNAME=root -e DB_PASSWORD="$PW" -e CACHE_STORE=array -e SESSION_DRIVER=array -e LINE_DRIVER=log \
    "$APP_IMAGE" php artisan migrate:status --pending || true

log "マイグレーションを試します"
set +e
docker run --rm --network "$NET" -v "$PWD":/var/www/html:ro --tmpfs /var/www/html/bootstrap/cache:rw,mode=1777 -w /var/www/html \
    -e APP_ENV=production -e APP_CONFIG_CACHE=/tmp/no-config-cache.php -e APP_EVENTS_CACHE=/tmp/no-events-cache.php \
    -e LOG_CHANNEL=stderr -e DB_CONNECTION=mysql -e DB_HOST="$DB" -e DB_PORT=3306 -e DB_DATABASE=irodori \
    -e DB_USERNAME=root -e DB_PASSWORD="$PW" -e CACHE_STORE=array -e SESSION_DRIVER=array -e LINE_DRIVER=log \
    "$APP_IMAGE" php artisan migrate --force
STATUS=$?
set -e
[ "$STATUS" = 0 ] || fail "マイグレーションが止まりました（上の理由を読んでください）。本番では、この状態のまま移行しないでください"

AFTER=$(counts)
log "マイグレーション後の件数：$AFTER"
[ "$BEFORE" = "$AFTER" ] || fail "件数が変わりました（前：$BEFORE ／ 後：${AFTER}）"

USERS=$(sql "select count(*) from users")
OWNERS=$(sql "select count(distinct user_id) from (select user_id from categories union all select user_id from tasks union all select user_id from task_series union all select user_id from notification_logs union all select user_id from line_connections) x")
NULLS=$(sql "select (select count(*) from categories where user_id is null)+(select count(*) from tasks where user_id is null)+(select count(*) from task_series where user_id is null)+(select count(*) from notification_logs where user_id is null)+(select count(*) from line_connections where user_id is null)")
SETTINGS=$(sql "select count(*) from user_settings")
log "ユーザー：${USERS}人 ／ データの持ち主：${OWNERS}人 ／ 持ち主のないデータ：${NULLS}件 ／ コピーした設定：${SETTINGS}件"
[ "$NULLS" = 0 ] || fail "持ち主のないデータが残っています"
[ "$OWNERS" -le 1 ] || fail "持ち主が2人以上になっています"

log "リハーサル成功：件数は同じで、データの持ち主はユーザー（${USERS}人）に決まりました。使い捨ての MySQL を消します"
