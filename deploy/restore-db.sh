#!/usr/bin/env bash
# 本番 MySQL をバックアップから戻す（EC2 の上で、ec2-user が動かす）
#   ./deploy/restore-db.sh ~/irodori-backups/irodori-YYYYMMDD-HHMMSS.sql.gz
#
# ・いまのデータは消えて、バックアップの時点に戻る（バックアップより後に足した予定はなくなる）
# ・始める前に、いまの状態を自動でバックアップする（戻したあとで「やっぱり前の方がよかった」に備える）
# ・実行前にプロジェクト名を入力して確認する（打ち間違いで動かないように）
# ・戻している間は scheduler を止めておくと安全：docker compose -p irodori-prod -f docker-compose.prod.yml stop scheduler
set -euo pipefail

PROJECT="${IRODORI_PROJECT:-irodori-prod}"
FILE="${1:-}"

fail() { printf '[restore] エラー：%s\n' "$*" >&2; exit 1; }

case "$PROJECT" in
    irodori-prod | irodori-prodcheck) ;;
    *) fail "対象にできないプロジェクトです：$PROJECT" ;;
esac
[ -n "$FILE" ] || fail "戻すバックアップのファイルを指定してください（例：~/irodori-backups/irodori-20261105-033000.sql.gz）"
[ -f "$FILE" ] || fail "ファイルがありません：$FILE"
gzip -t "$FILE" || fail "ファイルが壊れています：$FILE"
gzip -dc "$FILE" | tail -n 1 | grep -q '^-- Dump completed' || fail "最後まで保存されていないバックアップです：$FILE"

DB=$(docker ps -q --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.service=db")
[ -n "$DB" ] || fail "$PROJECT の db コンテナが動いていません"
[ "$(printf '%s\n' "$DB" | wc -l | tr -d ' ')" = 1 ] || fail "$PROJECT の db コンテナが複数あります"

printf '[restore] %s の MySQL を、次のバックアップの状態に戻します\n  %s\n' "$PROJECT" "$FILE"
printf '[restore] よければプロジェクト名（%s）を入力してください：' "$PROJECT"
read -r answer
[ "$answer" = "$PROJECT" ] || fail "入力が違うので、何もせずに終わります"

# 戻す前に、いまの状態を保存しておく
IRODORI_PROJECT="$PROJECT" "$(dirname "$0")/backup-db.sh"

gzip -dc "$FILE" | docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 "$MYSQL_DATABASE"'
echo "[restore] 戻しました。画面を開いて、予定が戻っていることを確かめてください"
