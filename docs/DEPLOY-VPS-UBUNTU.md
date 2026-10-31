# Deployment SE SMART PULSA ke VPS Ubuntu 24.04

Panduan untuk deployment langsung (tanpa Docker): PHP 8.3-FPM, PostgreSQL 16,
Redis, Nginx, Supervisor, Node.js 22. DNS domain harus mengarah ke VPS; port
80/443 terbuka. Jangan publikasikan PostgreSQL 5432 atau Redis 6379.

## 1. Source dan dependensi

Jalankan sebagai user deployment, bukan root. Jika direktori sudah berisi clone,
jangan clone ulang; periksa `git status` dan gunakan fast-forward pull.

```bash
cd /var/www
git clone https://github.com/Developer-Nota-service/SE-SMART-PULSA.git se-smart-pulsa
cd /var/www/se-smart-pulsa
git switch main
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --prefix web
npm run build --prefix web
```

## 2. PostgreSQL

```bash
sudo -u postgres psql
```

```sql
CREATE ROLE se_smart_pulsa LOGIN PASSWORD 'GANTI_DENGAN_PASSWORD_ACAK';
CREATE DATABASE se_smart_pulsa OWNER se_smart_pulsa;
\q
```

Gunakan password acak panjang. PostgreSQL hanya listen localhost/private
network; jangan membuka port 5432 pada firewall.

## 3. Environment

```bash
cd /var/www/se-smart-pulsa
cp .env.production.example .env
nano .env
php artisan key:generate
```

Isi APP_URL, password DB, SMTP, Fonnte, Midtrans, dan Digiflazz dengan secret
asli. Jangan commit .env. Jalankan key:generate hanya sekali; mengganti APP_KEY
membatalkan sesi dan membuat kredensial integrasi terenkripsi tak terbaca.

## 4. Permission dan database

Ganti `deploy` dengan user deployment VPS:

```bash
sudo chown -R deploy:www-data /var/www/se-smart-pulsa
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

Seeder produksi tidak membuat katalog demo. Jangan jalankan migrate:fresh atau
menghapus database produksi.

## 5. Nginx, PHP-FPM, HTTPS

```bash
sudo cp deploy/nginx/se-smart-pulsa.conf /etc/nginx/sites-available/se-smart-pulsa
sudo nano /etc/nginx/sites-available/se-smart-pulsa
sudo ln -s /etc/nginx/sites-available/se-smart-pulsa /etc/nginx/sites-enabled/se-smart-pulsa
sudo nginx -t
sudo systemctl enable --now php8.3-fpm
sudo systemctl reload nginx
```

Ubah `server_name` menjadi domain asli. Setelah DNS mengarah ke VPS, pasang
sertifikat TLS dengan Certbot:

```bash
sudo certbot --nginx -d domain-anda.example
```

## 6. Redis dan Supervisor

Pastikan Redis hanya menerima koneksi lokal:

```bash
sudo systemctl enable --now redis-server
redis-cli ping
sudo cp deploy/supervisor/se-smart-pulsa-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Worker harus berstatus RUNNING. Worker menunggu aman meskipun queue kosong.

## 7. Scheduler

```bash
sudo cp deploy/cron/se-smart-pulsa /etc/cron.d/se-smart-pulsa
sudo chmod 0644 /etc/cron.d/se-smart-pulsa
sudo systemctl enable --now cron
```

Jalankan `php artisan schedule:list` untuk memeriksa jadwal. Backup cron di
template sengaja nonaktif sampai pgpass dan backup off-server disiapkan.

## 8. Backup PostgreSQL

Buat `/var/lib/postgresql/.pgpass` dengan format
`localhost:5432:se_smart_pulsa:se_smart_pulsa:PASSWORD`, lalu
`sudo chmod 0600 /var/lib/postgresql/.pgpass`. Backup harus berada di luar web
root dan disalin berkala ke penyimpanan lain di luar VPS. Uji restore pada
database terpisah sebelum go-live.

```bash
sudo -u postgres env BACKUP_DIR=/var/backups/se-smart-pulsa DB_HOST=localhost DB_PORT=5432 DB_NAME=se_smart_pulsa DB_USER=se_smart_pulsa ./docker/backup-db.sh
```

## 9. Verifikasi

```bash
php artisan about
php artisan migrate:status
php artisan route:list --path=api/v1
php artisan schedule:list
sudo nginx -t
sudo supervisorctl status
redis-cli ping
```

Setelah DNS/HTTPS aktif: `/up` harus 200, `/app` memuat login, dan
`/api/v1/me` tanpa sesi harus 401. Pastikan OTP/email benar-benar diterima,
webhook Midtrans dan Digiflazz memakai URL HTTPS, katalog supplier tersinkron,
dan transaksi diuji dalam sandbox sebelum mode live.

## 10. Deployment berikutnya

Backup database dulu, lalu:

```bash
git pull --ff-only origin main
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --prefix web
npm run build --prefix web
php artisan migrate --force
php artisan optimize
sudo supervisorctl restart se-smart-pulsa-worker:*
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
```

Jangan gunakan git reset --hard, migrate:fresh, atau mengganti APP_KEY pada
deployment rutin.
