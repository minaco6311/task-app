#!/bin/sh
# 本番用 Compose（docker-compose.prod.yml）を、Mac の使い捨て環境で丸ごと確かめる。プロジェクトのルートで `sh e2e/prodcheck/run.sh`
#
#   1) テスト用のダミーの設定（ドメイン・秘密ヘッダー・DB のパスワード・APP_KEY）を e2e/.runtime/prodcheck/ に作る（Git 対象外）
#   2) 起動前の安全確認：プロジェクト名・ボリューム・ネットワーク・ポートが開発用と分かれているか
#   3) ソースを uid 1000 のコピーにして起動（EC2 で ec2-user が git clone した状態）→ マイグレーション → 確認用ユーザー
#   4) 確認：権限・Laravel の設定（LINE は line・外にはつながらない）・nginx の設定・HTTP（e2e/prodcheck/check.php）・バックアップと復元
#   5) 片付け：このプロジェクト（irodori-prodcheck）のコンテナ・ネットワーク・ボリューム・イメージと、ダミーの設定だけを消す
#
# 開発用（task_app）のコンテナ・MySQL・.env・storage/ には触れない。本番の秘密の値も作らない
# 片付けずに中を見たいとき：PRODCHECK_KEEP=1 sh e2e/prodcheck/run.sh（あとで同じコマンドの最後に down -v で消す）
set -eu

cd "$(dirname "$0")/../.."
PROJECT=irodori-prodcheck
RT=e2e/.runtime/prodcheck
DOMAIN=irodori.prodcheck.test
COMPOSE="docker compose -p $PROJECT --env-file $RT/compose.env -f docker-compose.prod.yml -f e2e/prodcheck/compose.override.yml"

FAILED=0
ok() { echo "  OK  $1"; }
ng() { echo "  NG  $1"; FAILED=$((FAILED + 1)); }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else ng "$1（$2）"; fi; }
stop() { echo "[prodcheck] 中止：$1" >&2; exit 1; }

cleanup() {
    if [ "${PRODCHECK_KEEP:-}" = 1 ]; then
        echo "[prodcheck] PRODCHECK_KEEP=1 なので片付けていません（${PROJECT}）"
        return
    fi
    $COMPOSE down -v --rmi local --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$RT"
}

# ---------- 1) ダミーの設定（画面には出さない） ----------
umask 077
rm -rf "$RT"
mkdir -p "$RT"
# 安全確認が終わるまでは、ダミーの設定を消すだけ（コンテナにはまだ触れない）
trap 'rm -rf "$RT"' EXIT
hex() { openssl rand -hex "$1"; }
DB_PASS=$(hex 16)
DB_ROOT=$(hex 16)
PC_SECRET="prodcheck$(hex 24)"
PC_PASSWORD="pc-$(hex 12)"
PC_EMAIL=owner@prodcheck.test
export PC_SECRET PC_PASSWORD PC_EMAIL

# Compose が読む値（docker-compose.prod.yml の ${DB_DATABASE} など）
printf 'DB_DATABASE=irodori\nDB_USERNAME=irodori\nDB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\nAPP_UID=1000\nAPP_GID=1000\n' "$DB_PASS" "$DB_ROOT" > "$RT/compose.env"
# nginx が読む値（.env.nginx の代わり）
printf 'APP_DOMAIN=%s\nORIGIN_VERIFY_SECRET=%s\n' "$DOMAIN" "$PC_SECRET" > "$RT/env.nginx"
# Laravel の .env：本番の見本をもとにする。わざと APP_ENV=local・APP_DEBUG=true・SESSION_SECURE_COOKIE=false・LINE_DRIVER=log にして、
# docker-compose.prod.yml の environment が優先されることを確かめる
sed -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
    -e "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" \
    -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$DB_ROOT|" \
    -e "s|^APP_ENV=.*|APP_ENV=local|" \
    -e "s|^APP_DEBUG=.*|APP_DEBUG=true|" \
    -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|" \
    -e "s|^LINE_DRIVER=.*|LINE_DRIVER=log|" \
    .env.production.example > "$RT/app.env"

# ---------- 2) 起動前の安全確認（何も作らない。設定の値は画面に出さない） ----------
echo "[prodcheck] 起動前の安全確認"
CONFIG=$($COMPOSE config) || stop "Compose の設定を読めません"
[ "$(printf '%s\n' "$CONFIG" | grep -E '^name:')" = "name: $PROJECT" ] || stop "プロジェクト名が $PROJECT ではありません"
VOLS=$(printf '%s\n' "$CONFIG" | sed -n '/^volumes:/,$p' | grep -E '^    name:' | sed 's/^    name: //' | sort | tr '\n' ' ')
[ "$VOLS" = "${PROJECT}_db-data ${PROJECT}_src " ] || stop "ボリュームが想定と違います：$VOLS"
NETS=$(printf '%s\n' "$CONFIG" | sed -n '/^networks:/,/^[a-z]/p' | grep -E '^    name:' | sed 's/^    name: //')
[ "$NETS" = "${PROJECT}_default" ] || stop "ネットワークが想定と違います：$NETS"
printf '%s\n' "$CONFIG" | sed -n '/^networks:/,/^[a-z]/p' | grep -q 'internal: true' || stop "ネットワークが internal ではありません"
! printf '%s\n' "$CONFIG" | grep -q 'published:' || stop "外に公開するポートがあります"
ok "プロジェクト $PROJECT ／ ボリューム ${VOLS}／ ネットワーク ${NETS}（internal）／ 公開ポートなし"

trap cleanup EXIT

# ---------- 3) 起動 ----------
echo "[prodcheck] ビルド（target: prod）"
$COMPOSE build -q app scheduler

echo "[prodcheck] ソースを使い捨てボリュームにコピー（Git で管理しているファイル＋vendor/・public/build/。.env などは入れない）"
[ -f public/build/manifest.json ] || stop "public/build がありません（先に npm run build）"
{ git ls-files -co --exclude-standard; find vendor public/build -type f; } > "$RT/files.txt"
COPYFILE_DISABLE=1 tar -cf - -T "$RT/files.txt" \
    | $COMPOSE run --rm --no-deps -T -u root app sh -c '
        tar -xf - -C /var/www/html --warning=no-unknown-keyword --no-same-owner
        cp e2e/prodcheck/probe.php public/__prodcheck.php
        chown -R 1000:1000 /var/www/html'
$COMPOSE run --rm --no-deps -T -u root app sh -c 'cat > .env && chown 1000:1000 .env && chmod 600 .env' < "$RT/app.env"

echo "[prodcheck] 起動"
$COMPOSE up -d --wait --wait-timeout 180 db app web scheduler >/dev/null
$COMPOSE exec -T app php artisan migrate --force >/dev/null || stop "マイグレーションに失敗しました"
expect "確認用のユーザーを作る" "$($COMPOSE exec -T -e PC_EMAIL -e PC_PASSWORD app php artisan tinker --execute='App\Models\User::create(["name" => "確認用", "email" => getenv("PC_EMAIL"), "password" => getenv("PC_PASSWORD")]); echo App\Models\User::count();')" 1

# ---------- 4) 確認 ----------
echo "[prodcheck] 動かしているユーザー（uid 1000 = ec2-user）"
expect "app の uid" "$($COMPOSE exec -T app id -u)" 1000
expect "scheduler の uid" "$($COMPOSE exec -T scheduler id -u)" 1000
expect "app の php-fpm（PID 1）の uid" "$($COMPOSE exec -T app awk '/^Uid:/ {print $2}' /proc/1/status)" 1000

echo "[prodcheck] Laravel の設定（.env にわざと違う値を書いてある）"
SETTINGS='echo json_encode([config("app.env"), config("app.debug"), config("session.secure"), config("line.driver"), get_class(app(App\Services\Line\Messenger::class)), url("/")]);'
WANT="[\"production\",false,true,\"line\",\"App\\\\Services\\\\Line\\\\MessagingApiMessenger\",\"https:\\/\\/$DOMAIN\"]"
expect "app：production・debug off・Secure Cookie・LINE は line・https の URL" "$($COMPOSE exec -T app php artisan tinker --execute="$SETTINGS")" "$WANT"
expect "scheduler：同じ" "$($COMPOSE exec -T scheduler php artisan tinker --execute="$SETTINGS")" "$WANT"
$COMPOSE exec -T app php artisan config:cache >/dev/null
expect "config:cache のあとも同じ（本番の手順）" "$($COMPOSE exec -T app php artisan tinker --execute="$SETTINGS")" "$WANT"
$COMPOSE exec -T app php artisan config:clear >/dev/null

echo "[prodcheck] nginx の設定"
if $COMPOSE exec -T web nginx -t >/dev/null 2>&1; then ok "nginx -t（構文）"; else ng "nginx -t（構文）"; fi
expect "NGINX_ENVSUBST_FILTER（\$\$ が \$ として渡る）" "$($COMPOSE exec -T web printenv NGINX_ENVSUBST_FILTER)" '^(APP_DOMAIN|ORIGIN_VERIFY_SECRET)$'
expect "置き換え後に \${ が残っていない" "$($COMPOSE exec -T web grep -c '\${' /etc/nginx/conf.d/default.conf || true)" 0
expect "server_name に APP_DOMAIN が入る" "$($COMPOSE exec -T web grep -c "server_name $DOMAIN;" /etc/nginx/conf.d/default.conf)" 1
expect "nginx の変数（\$uri など）は置き換わらない" "$($COMPOSE exec -T web grep -c 'try_files \$uri \$uri/ /index.php?\$query_string;' /etc/nginx/conf.d/default.conf)" 1
expect "読み込まれる設定は1つだけ（conf.d/default.conf）" "$($COMPOSE exec -T web sh -c 'ls /etc/nginx/conf.d')" default.conf
# 設定を間違えたとき：起動しない（＝秘密ヘッダーなしで通してしまわない）。使い捨ての web を1回だけ動かして nginx -t
nginx_t() { $COMPOSE run --rm --no-deps -T "$@" web nginx -t >/dev/null 2>&1; }
if nginx_t -e APP_DOMAIN=; then ng "APP_DOMAIN が空なら起動しない"; else ok "APP_DOMAIN が空なら起動しない"; fi
if nginx_t -e NGINX_ENVSUBST_FILTER='^APP_DOMAIN$'; then ng "ORIGIN_VERIFY_SECRET が置き換わらない（未設定）なら起動しない"; else ok "ORIGIN_VERIFY_SECRET が置き換わらない（未設定）なら起動しない"; fi
if nginx_t -e ORIGIN_VERIFY_SECRET=; then ok "ORIGIN_VERIFY_SECRET が空でも構文は通る"; else ng "ORIGIN_VERIFY_SECRET が空のときの構文"; fi
# 空のまま起動した nginx（別名で1つだけ）に、ヘッダーなし・空・何かの値 を送る → すべて 403
$COMPOSE run -d --rm --no-deps -e ORIGIN_VERIFY_SECRET= --name "$PROJECT-web-empty" web >/dev/null
sleep 2
EMPTY=$($COMPOSE run --rm --no-deps -T -e PC_DOMAIN="$DOMAIN" app php -r '
    $codes = [];
    foreach ([null, "", "anything"] as $v) {
        $fp = fsockopen("irodori-prodcheck-web-empty", 80);
        fwrite($fp, "GET /up HTTP/1.0\r\nHost: ".getenv("PC_DOMAIN")."\r\n".($v === null ? "" : "X-Origin-Verify: $v\r\n")."\r\n");
        preg_match("#^HTTP/\S+ (\d+)#", (string) stream_get_contents($fp), $m);
        $codes[] = $m[1] ?? "0";
    }
    echo implode(",", $codes);')
docker stop "$PROJECT-web-empty" >/dev/null
expect "ORIGIN_VERIFY_SECRET が空のまま起動したら、ヘッダーなし・空・値ありのどれも 403" "$EMPTY" "403,403,403"

echo "[prodcheck] HTTP（CloudFront のふりをして nginx を叩く）"
if $COMPOSE run --rm --no-deps -T -e PC_DOMAIN="$DOMAIN" -e PC_SECRET -e PC_EMAIL -e PC_PASSWORD app php e2e/prodcheck/check.php; then
    ok "HTTP の確認"
else
    ng "HTTP の確認"
fi

echo "[prodcheck] 書き込みとファイルの持ち主"
$COMPOSE exec -T app php artisan tinker --execute='Illuminate\Support\Facades\Log::info("prodcheck");' >/dev/null
$COMPOSE exec -T app php artisan view:cache >/dev/null
expect "ログ（storage/logs/laravel-日付.log）ができる" "$($COMPOSE exec -T app sh -c 'ls storage/logs | grep -c "^laravel-.*\.log$"')" 1
expect "storage/・bootstrap/cache/ に uid 1000 以外のファイルがない" "$($COMPOSE exec -T -u root app sh -c 'find storage bootstrap/cache ! -user 1000 | wc -l')" 0

echo "[prodcheck] バックアップと復元（この使い捨て DB だけ）"
BK="$RT/backups"
mkdir -p "$BK"
touch -t 200001010000 "$BK/irodori-20000101-000000.sql.gz" "$BK/keep-me.txt"
users() { $COMPOSE exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM users"'; }
if IRODORI_PROJECT=task_app BACKUP_DIR="$BK" deploy/backup-db.sh >/dev/null 2>&1; then ng "開発用（task_app）を指定すると断る"; else ok "開発用（task_app）を指定すると断る"; fi
IRODORI_PROJECT=$PROJECT BACKUP_DIR="$BK" KEEP_DAYS=14 deploy/backup-db.sh >/dev/null
FILE=$(ls "$BK"/irodori-2*.sql.gz | grep -v 20000101 | head -n 1)
[ -n "$FILE" ] && ok "バックアップができる" || ng "バックアップができる"
expect "ファイルの権限は 600" "$(ls -l "$FILE" | cut -c1-10)" "-rw-------"
expect "フォルダの権限は 700" "$(ls -ld "$BK" | cut -c1-10)" "drwx------"
[ ! -e "$BK/irodori-20000101-000000.sql.gz" ] && ok "保存期間を過ぎたバックアップは消える" || ng "保存期間を過ぎたバックアップは消える"
[ -e "$BK/keep-me.txt" ] && ok "バックアップ以外のファイルは消さない" || ng "バックアップ以外のファイルは消さない"
expect "復元前：ユーザー 1" "$(users)" 1
# データが消えた状態を作る（使い捨ての DB だけ。複数ユーザー化でユーザーに予定などがつながっているので、外部キーの確認を外して消す）
$COMPOSE exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET FOREIGN_KEY_CHECKS=0; DELETE FROM users"'
expect "（わざと消した）ユーザー 0" "$(users)" 0
if echo wrong | IRODORI_PROJECT=$PROJECT BACKUP_DIR="$BK" deploy/restore-db.sh "$FILE" >/dev/null 2>&1; then ng "確認の入力が違うと復元しない"; else ok "確認の入力が違うと復元しない"; fi
expect "（復元していないので）ユーザー 0 のまま" "$(users)" 0
echo "$PROJECT" | IRODORI_PROJECT=$PROJECT BACKUP_DIR="$BK" deploy/restore-db.sh "$FILE" >/dev/null
expect "復元後：ユーザー 1 に戻る" "$(users)" 1
expect "復元の前に、その時点のバックアップを自動で作る" "$(ls "$BK"/irodori-2*.sql.gz | grep -vc 20000101)" 2
IRODORI_PROJECT=$PROJECT BACKUP_DIR="$BK" deploy/backup-db.sh >/dev/null
IRODORI_PROJECT=$PROJECT BACKUP_DIR="$BK" deploy/backup-db.sh >/dev/null
expect "続けて2回動かしても、前のファイルを上書きしない" "$(ls "$BK"/irodori-2*.sql.gz | grep -vc 20000101)" 4
expect "最初のバックアップは消えずに残る" "$([ -f "$FILE" ] && echo yes)" yes

echo
if [ "$FAILED" -eq 0 ]; then
    echo "[prodcheck] すべて OK"
else
    echo "[prodcheck] NG が $FAILED 件あります"
    exit 1
fi
