# Deployment SE SMART PULSA ke VPS Debian 12

Panduan instalasi native (tanpa Docker): PHP 8.4-FPM, PostgreSQL 16, Redis,
Nginx, Supervisor, Node.js 22, dan Certbot. Jalankan perintah sebagai user
deployment kecuali bila diawali `sudo`. Domain harus mengarah ke VPS dan hanya
port SSH, HTTP, serta HTTPS yang boleh dibuka ke internet.

## 1. Perbarui sistem dan siapkan user

```bash
sudo apt update
sudo apt full-upgrade -y
sudo apt install -y ca-certificates curl gnupg lsb-release apt-transport-https \
  git unzip supervisor nginx cron certbot python3-certbot-nginx
sudo adduser deploy
sudo usermod -aG www-data deploy
sudo install -d -o deploy -g www-data /var/www
```

Atur firewall provider atau `nftables` agar port `5432` (PostgreSQL) dan `6379`
(Redis) tidak dapat diakses dari luar VPS. Pakai autentikasi SSH key dan matikan
login SSH root setelah akses user deployment teruji.

## 2. Pasang runtime dari repositori bertanda tangan

Debian 12 menyediakan versi PHP dan Node.js yang lebih lama dari persyaratan
aplikasi. Gunakan repositori paket resmi/tepercaya di bawah ini dan tinjau
kebijakan pembaruannya.

### PHP 8.4

```bash
curl -fsSLo /tmp/debsuryorg-archive-keyring.deb \
  https://packages.sury.org/debsuryorg-archive-keyring.deb
sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ bookworm main" \
  | sudo tee /etc/apt/sources.list.d/php-sury.list
```

### PostgreSQL 16 dari PGDG

```bash
sudo install -d -m 0755 /usr/share/postgresql-common/pgdg
sudo curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
  -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
  | sudo tee /etc/apt/sources.list.d/pgdg.list
```

### Node.js 22

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x -o /tmp/nodesource_setup.sh
less /tmp/nodesource_setup.sh
sudo bash /tmp/nodesource_setup.sh
```

Pasang runtime dan ekstensi:

```bash
sudo apt update
sudo apt install -y \
  php8.4-cli php8.4-fpm php8.4-common php8.4-bcmath php8.4-curl \
  php8.4-gd php8.4-intl php8.4-mbstring php8.4-opcache php8.4-pgsql \
  php8.4-redis php8.4-xml php8.4-zip \
  postgresql-16 postgresql-client-16 redis-server \
  nodejs composer
php -v
php -m
node --version
npm --version
psql --version
```

Pastikan `php -v` menunjukkan PHP 8.4, `node --version` menunjukkan Node.js 22,
dan modul `bcmath`, `curl`, `gd`, `intl`, `mbstring`, `pcntl`, `pdo_pgsql`,
`redis`, `xml`, serta `zip` tersedia.

## 3. Kunci PostgreSQL dan Redis ke VPS

Pastikan PostgreSQL hanya listen pada localhost, misalnya
`listen_addresses = 'localhost'` pada `/etc/postgresql/16/main/postgresql.conf`.
Jangan menambahkan port publik atau membuka `5432` pada firewall.

Buat role dan database khusus aplikasi:

```bash
sudo -u postgres psql
```

```sql
CREATE ROLE se_smart_pulsa LOGIN PASSWORD 'GANTI_DENGAN_PASSWORD_ACAK_PANJANG';
CREATE DATABASE se_smart_pulsa OWNER se_smart_pulsa;
\q
```

Pastikan Redis hanya bind ke `127.0.0.1 ::1` dan `protected-mode yes` pada
`/etc/redis/redis.conf`. Bila memasang `requirepass`, gunakan password acak dan
isi nilai yang sama pada `REDIS_PASSWORD` di `.env`. Aktifkan layanan:

```bash
sudo systemctl enable --now postgresql redis-server nginx supervisor cron
redis-cli ping
```

`redis-cli ping` harus menjawab `PONG` bila Redis tidak memakai password.

## 4. Ambil kode dan build frontend

Login sebagai `deploy`:

```bash
sudo -iu deploy
cd /var/www
git clone https://github.com/Developer-Nota-service/SE-SMART-PULSA.git se-smart-pulsa
cd /var/www/se-smart-pulsa
git switch main
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --prefix web
npm run build --prefix web
```

Untuk pembaruan berikutnya, periksa `git status` dahulu dan gunakan
`git pull --ff-only origin main`. Jangan menghapus repo, memakai `git reset
--hard`, atau menjalankan `migrate:fresh` pada server produksi.

## 5. Environment dan database

```bash
cd /var/www/se-smart-pulsa
cp .env.production.example .env
nano .env
```

Isi sekurangnya `APP_URL`, identitas pemilik, database, Redis, SMTP, Fonnte,
Midtrans, dan Digiflazz dengan nilai asli. Gunakan URL HTTPS final untuk
`APP_URL` dan `MIDTRANS_FINISH_URL`. Jangan commit `.env` atau menaruh secret
di konfigurasi Nginx. Jangan aktifkan transaksi live sebelum uji sandbox lulus.

Set `TRUSTED_PROXIES` sesuai reverse proxy yang benar-benar digunakan. Jika Nginx
langsung menerima trafik, biarkan kosong. Jangan mengatur `*` tanpa proxy tepercaya.
Buat `APP_KEY` hanya sekali; menggantinya akan membatalkan sesi dan membuat
kredensial integrasi terenkripsi tidak dapat dibaca.

```bash
php artisan key:generate
exit
sudo chown -R deploy:www-data /var/www/se-smart-pulsa
sudo chmod -R ug+rwX /var/www/se-smart-pulsa/storage /var/www/se-smart-pulsa/bootstrap/cache
sudo chmod 640 /var/www/se-smart-pulsa/.env
sudo -iu deploy
cd /var/www/se-smart-pulsa
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
```

Seeder produksi tidak membuat data/katalog demo. Setelah kredensial produksi
Digiflazz dipasang dan diverifikasi:

```bash
php artisan digiflazz:sync-products
```

## 6. Nginx dan HTTPS

```bash
sudo cp /var/www/se-smart-pulsa/deploy/nginx/se-smart-pulsa.conf \
  /etc/nginx/sites-available/se-smart-pulsa
sudo nano /etc/nginx/sites-available/se-smart-pulsa
sudo ln -s /etc/nginx/sites-available/se-smart-pulsa \
  /etc/nginx/sites-enabled/se-smart-pulsa
sudo nginx -t
sudo systemctl enable --now php8.4-fpm
sudo systemctl reload nginx
```

Ubah `server_name` ke domain yang benar dan pastikan document root menunjuk ke
`/var/www/se-smart-pulsa/public`. Konfigurasi memakai socket
`/run/php/php8.4-fpm.sock`. Setelah DNS mengarah ke VPS, pasang TLS:

```bash
sudo certbot --nginx -d domain-anda.example
sudo certbot renew --dry-run
```

## 7. Queue dan scheduler

```bash
sudo cp /var/www/se-smart-pulsa/deploy/supervisor/se-smart-pulsa-worker.conf \
  /etc/supervisor/conf.d/se-smart-pulsa-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status

sudo cp /var/www/se-smart-pulsa/deploy/cron/se-smart-pulsa \
  /etc/cron.d/se-smart-pulsa
sudo chmod 0644 /etc/cron.d/se-smart-pulsa
sudo systemctl enable --now cron
sudo -iu deploy
cd /var/www/se-smart-pulsa
php artisan schedule:list
```

Worker harus berstatus `RUNNING`. Cron menjalankan scheduler tiap menit untuk
sinkronisasi terjadwal. Periksa log Laravel, Nginx, PHP-FPM, dan worker sesudah
aktivasi.

## 8. Backup dan pemulihan

Simpan backup di luar repo dan web root, batasi permission, lalu salin berkala ke
penyimpanan off-server. Siapkan `/var/lib/postgresql/.pgpass` dengan format
`localhost:5432:se_smart_pulsa:se_smart_pulsa:PASSWORD` dan mode `0600` milik user
`postgres`.

```bash
sudo install -d -o postgres -g postgres -m 0700 /var/backups/se-smart-pulsa
sudo -u postgres env \
  BACKUP_DIR=/var/backups/se-smart-pulsa \
  COMPOSE_FILE=/nonexistent-compose-file.yaml \
  DB_HOST=localhost DB_PORT=5432 \
  DB_NAME=se_smart_pulsa DB_USER=se_smart_pulsa \
  sh /var/www/se-smart-pulsa/docker/backup-db.sh
```

`COMPOSE_FILE` sengaja menunjuk ke file yang tidak ada agar skrip backup memilih
`pg_dump` lokal, bukan jalur Docker. Jadwalkan backup setelah uji manual berhasil;
pastikan retensi, salinan off-server, dan uji restore ke database terpisah.
Folder upload privat di `storage/app/private` juga harus dicakup backup.

## 9. Verifikasi go-live

```bash
php artisan about
php artisan migrate:status
php artisan route:list --path=api/v1
php artisan schedule:list
sudo nginx -t
sudo supervisorctl status
redis-cli ping
```

Setelah DNS dan HTTPS aktif, pastikan `/up` menjawab `200`, `/app` memuat halaman
login, dan `/api/v1/me` tanpa sesi menjawab `401`. Uji OTP email/WhatsApp,
sinkron katalog, dan transaksi sandbox. Daftarkan webhook:

- Midtrans: `https://domain-anda.example/api/v1/midtrans/notification`
- Digiflazz: `https://domain-anda.example/api/v1/digiflazz/webhook`

Aktifkan mode live hanya setelah callback, saldo, refund, dan transaksi berhasil
diuji. PostgreSQL dan Redis tetap privat; jangan pernah membuka port `5432` atau
`6379` ke internet.

## Sumber paket

- PHP: [packages.sury.org/php](https://packages.sury.org/php/)
- PostgreSQL: [repositori PGDG untuk Debian](https://www.postgresql.org/download/linux/debian/)
- Node.js: [NodeSource distributions](https://github.com/nodesource/distributions)
