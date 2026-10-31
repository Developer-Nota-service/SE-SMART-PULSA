#!/bin/sh
# Backup PostgreSQL ke arsip SQL gzip. Bisa dipakai pada VPS langsung atau Docker.
# VPS: set DB_HOST/DB_PORT/DB_NAME/DB_USER dan siapkan ~/.pgpass (0600).
# Docker: bila COMPOSE_FILE ada, jalankan pg_dump dari service DB.
set -eu

BACKUP_DIR=${BACKUP_DIR:-./backups}
KEEP_DAYS=${KEEP_DAYS:-14}
COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.yml}
DB_SERVICE=${DB_SERVICE:-db}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-5432}
DB_USER=${DB_USER:-se_smart_pulsa}
DB_NAME=${DB_NAME:-se_smart_pulsa}

mkdir -p "$BACKUP_DIR"
file="$BACKUP_DIR/se-smart-pulsa-$(date +%Y%m%d-%H%M%S).sql.gz"

if [ -f "$COMPOSE_FILE" ] && command -v docker >/dev/null 2>&1; then
    if ! docker compose -f "$COMPOSE_FILE" exec -T "$DB_SERVICE" pg_dump -U "$DB_USER" -d "$DB_NAME" | gzip > "$file"; then
        rm -f "$file"
        echo "Backup Docker gagal." >&2
        exit 1
    fi
else
    if ! pg_dump --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" --dbname="$DB_NAME" | gzip > "$file"; then
        rm -f "$file"
        echo "Backup PostgreSQL VPS gagal; periksa koneksi dan .pgpass." >&2
        exit 1
    fi
fi

if [ ! -s "$file" ]; then
    rm -f "$file"
    echo "Backup gagal: file kosong." >&2
    exit 1
fi

find "$BACKUP_DIR" -name 'se-smart-pulsa-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
echo "Backup selesai: $file ($(du -h "$file" | cut -f1))"
