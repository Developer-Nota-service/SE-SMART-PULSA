#!/bin/sh
# Entrypoint image produksi (Apache + PHP 8.4).
#
# Tugasnya: siapkan database Postgres yang masih kosong (database baru di
# hosting) sebelum Apache melayani request — migrasi skema, isi data demo
# (idempotent), lalu cache konfigurasi. Tanpa ini container hasil Dockerfile
# jalan dengan tabel kosong dan setiap request /api/v1 gagal 500.
set -e

# Tunggu database siap: service Postgres di hosting bisa belum menerima koneksi
# saat container aplikasi start. Migrasi diulang maksimal 30x (~60 detik).
attempt=1
while :; do
    if php artisan migrate --force --no-interaction; then
        break
    fi

    if [ "$attempt" -ge 30 ]; then
        echo "Migrasi gagal setelah ${attempt} percobaan — periksa DATABASE_URL / DB_*." >&2
        exit 1
    fi

    echo "Database belum siap (percobaan ${attempt}), ulangi dalam 2 detik..." >&2
    attempt=$((attempt + 1))
    sleep 2
done

# Seeder idempotent (users/products/riwayat demo) — aman dijalankan setiap boot.
php artisan db:seed --force --no-interaction

php artisan optimize --no-interaction

exec apache2-foreground
