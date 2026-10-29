#!/bin/sh
# Backup harian PostgreSQL ke file .sql.gz.
#
# Jalankan dari root repo (host/server), mis. lewat cron:
#   0 2 * * * cd /path/ke/aplikasi && ./docker/backup-db.sh >> /var/log/pulsa-backup.log 2>&1
#
# Variabel opsional:
#   BACKUP_DIR   direktori tujuan (default ./backups) — sebaiknya di luar server
#                (rsync/S3) supaya aman bila VPS hilang.
#   KEEP_DAYS    umur backup yang disimpan (default 14 hari).
#   COMPOSE_FILE file compose yang dipakai (default docker-compose.yml).
#   DB_SERVICE   nama service Postgres (default db).
#   DB_USER      user Postgres (default pulsa).
#   DB_NAME      nama database (default pulsa_laravel).
set -eu

BACKUP_DIR=${BACKUP_DIR:-./backups}
KEEP_DAYS=${KEEP_DAYS:-14}
COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.yml}
DB_SERVICE=${DB_SERVICE:-db}
DB_USER=${DB_USER:-pulsa}
DB_NAME=${DB_NAME:-pulsa_laravel}

mkdir -p "$BACKUP_DIR"

file="$BACKUP_DIR/pulsa-$(date +%Y%m%d-%H%M%S).sql.gz"

if ! docker compose -f "$COMPOSE_FILE" exec -T "$DB_SERVICE" \
    pg_dump -U "$DB_USER" -d "$DB_NAME" | gzip > "$file"; then
    rm -f "$file"
    echo "Backup gagal: pg_dump tidak berhasil." >&2
    exit 1
fi

# Backup kosong (mis. container mati) tidak boleh menimpa riwayat yang sah.
if [ ! -s "$file" ]; then
    rm -f "$file"
    echo "Backup gagal: file kosong." >&2
    exit 1
fi

find "$BACKUP_DIR" -name 'pulsa-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

echo "Backup selesai: $file ($(du -h "$file" | cut -f1))"
