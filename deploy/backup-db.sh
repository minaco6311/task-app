#!/usr/bin/env bash
# 本番 MySQL のバックアップ（EC2 の上で、リポジトリを置いたユーザー（ec2-user）が動かす）
#   手で：      ./deploy/backup-db.sh
#   毎日自動で：deploy/systemd/irodori-backup.timer（手順は docs/aws-deploy.md）
#
# ・mysqldump で、予定・カテゴリ・設定・ユーザー・LINE連携（暗号化したまま）・通知履歴などを1つのファイルに保存する
# ・保存先：BACKUP_DIR（標準は ~/irodori-backups。リポジトリの外なので Git に入らない）
#   フォルダは 700、ファイルは 600（本人だけが読める）
# ・KEEP_DAYS 日（標準 14 日）より古いバックアップ（irodori-*.sql.gz）だけを消す。ほかのファイルには触れない
# ・パスワードは画面やコマンドの引数に出さない（db コンテナの中の環境変数 MYSQL_ROOT_PASSWORD を使う）
set -euo pipefail

PROJECT="${IRODORI_PROJECT:-irodori-prod}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/irodori-backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"

log() { printf '[backup] %s\n' "$*"; }
fail() { printf '[backup] エラー：%s\n' "$*" >&2; exit 1; }

# 対象は本番（irodori-prod）と、Mac での確認用（irodori-prodcheck）だけ。開発用の task_app などは断る
case "$PROJECT" in
    irodori-prod | irodori-prodcheck) ;;
    *) fail "対象にできないプロジェクトです：$PROJECT" ;;
esac
case "$KEEP_DAYS" in
    '' | *[!0-9]* | 0) fail "KEEP_DAYS は 1 以上の数字にしてください" ;;
esac

# Compose のラベルで、そのプロジェクトの db コンテナを1つだけ選ぶ
DB=$(docker ps -q --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.service=db")
[ -n "$DB" ] || fail "$PROJECT の db コンテナが動いていません"
[ "$(printf '%s\n' "$DB" | wc -l | tr -d ' ')" = 1 ] || fail "$PROJECT の db コンテナが複数あります"

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

STAMP=$(date +%Y%m%d-%H%M%S)
# 同じ秒に2回動いても（復元の直前の自動バックアップなど）、前のファイルを上書きしない
OUT="$BACKUP_DIR/irodori-$STAMP.sql.gz"
n=2
while [ -e "$OUT" ]; do
    OUT="$BACKUP_DIR/irodori-$STAMP-$n.sql.gz"
    n=$((n + 1))
done
PART="$OUT.part"
trap 'rm -f "$PART"' EXIT

log "$PROJECT の MySQL を保存します → $OUT"
# --single-transaction：アプリを止めずに、ある瞬間の状態をそろえて保存する（InnoDB）
docker exec "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot \
    --single-transaction --quick --routines --triggers --no-tablespaces \
    --default-character-set=utf8mb4 "$MYSQL_DATABASE"' | gzip -9 > "$PART"

# 途中で切れていないか（mysqldump は最後に「Dump completed」と書く）
gzip -t "$PART" || fail "保存したファイルが壊れています"
gzip -dc "$PART" | tail -n 1 | grep -q '^-- Dump completed' || fail "保存が最後まで終わっていません"

[ ! -e "$OUT" ] || fail "同じ名前のファイルがあります：$OUT"
mv "$PART" "$OUT"
chmod 600 "$OUT"
log "保存しました（$(du -h "$OUT" | cut -f1)）"

# 古いバックアップを消す（このフォルダの irodori-*.sql.gz だけ）
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'irodori-*.sql.gz' -mtime +"$KEEP_DAYS" -print -delete \
    | sed 's/^/[backup] 古いバックアップを削除：/'
