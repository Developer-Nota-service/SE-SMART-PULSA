#!/bin/sh
# Backup PostgreSQL ke arsip SQL gzip. Gunakan pgpass mode 0600 atau secret manager.
set -eu

BACKUP_DIR=${BACKUP_DIR:-./backups}
KEEP_DAYS=${KEEP_DAYS:-14}
COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.yml}
DB_SERVICE=${DB_SERVICE:-db}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-5432}
DB_USER=${DB_USER:-se_smart_pulsa}
DB_NAME=${DB_NAME:-se_smart_pulsa}

umask 077
mkdir -p "$BACKUP_DIR"
stamp=$(date +%Y%m%d-%H%M%S)
file="$BACKUP_DIR/se-smart-pulsa-$stamp.sql.gz"
tmp="$BACKUP_DIR/.se-smart-pulsa-$stamp.sql"
trap 'rm -f "$tmp" "$file.tmp"' EXIT HUP INT TERM

if [ -f "$COMPOSE_FILE" ] && command -v docker >/dev/null 2>&1; then
    docker compose -f "$COMPOSE_FILE" exec -T "$DB_SERVICE" pg_dump -U "$DB_USER" -d "$DB_NAME" > "$tmp"
else
    pg_dump --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" --dbname="$DB_NAME" > "$tmp"
fi

if [ ! -s "$tmp" ]; then
    echo "Backup gagal: pg_dump menghasilkan file kosong." >&2
    exit 1
fi

gzip -c "$tmp" > "$file.tmp"
mv "$file.tmp" "$file"
rm -f "$tmp"
trap - EXIT HUP INT TERM

find "$BACKUP_DIR" -name 'se-smart-pulsa-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
echo "Backup selesai: $file ($(du -h "$file" | cut -f1))"
