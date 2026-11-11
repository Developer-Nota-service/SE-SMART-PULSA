#!/usr/bin/env bash
# SE SMART PULSA - Instalasi otomatis untuk Debian 12 (native, tanpa Docker)
# Jalankan sebagai root:
#   curl -fsSL https://raw.githubusercontent.com/Developer-Nota-service/SE-SMART-PULSA/main/deploy/install-debian-12.sh -o /tmp/install.sh && bash /tmp/install.sh
set -euo pipefail

DOMAIN="${DOMAIN:-sesmartpulsa.my.id}"
REPO_URL="https://github.com/Developer-Nota-service/SE-SMART-PULSA.git"
BRANCH="${BRANCH:-main}"
APP_DIR="/var/www/se-smart-pulsa"
DB_NAME="se_smart_pulsa"
DB_USER="se_smart_pulsa"
PHP_VER="8.4"

log() { echo -e "\n\033[1;36m==> $1\033[0m"; }
warn() { echo -e "\033[1;33m[!] $1\033[0m"; }

# Root check
if [ "$(id -u)" -ne 0 ]; then echo "Jalankan sebagai root."; exit 1; fi

export DEBIAN_FRONTEND=noninteractive

# 1. Update sistem
log "Memperbarui sistem..."
apt update
apt full-upgrade -y
apt install -y ca-certificates curl gnupg lsb-release apt-transport-https \
  git unzip supervisor nginx cron certbot python3-certbot-nginx acl

# 2. Repositori PHP 8.4 (Sury)
log "Menambah repositori PHP 8.4..."
curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
dpkg -i /tmp/debsuryorg-archive-keyring.deb
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ bookworm main" \
  > /etc/apt/sources.list.d/php-sury.list

# 3. PostgreSQL 16 (PGDG)
log "Menambah repositori PostgreSQL..."
install -d -m 0755 /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
  > /etc/apt/sources.list.d/pgdg.list

# 4. Node.js 22
log "Menambah repositori Node.js 22..."
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -

# 5. Install semua paket
log "Menginstal PHP, PostgreSQL, Redis, Node.js..."
apt update
apt install -y \
  php${PHP_VER}-cli php${PHP_VER}-fpm php${PHP_VER}-common php${PHP_VER}-bcmath php${PHP_VER}-curl \
  php${PHP_VER}-gd php${PHP_VER}-intl php${PHP_VER}-mbstring php${PHP_VER}-opcache php${PHP_VER}-pgsql \
  php${PHP_VER}-redis php${PHP_VER}-xml php${PHP_VER}-zip \
  postgresql-16 postgresql-client-16 redis-server \
  nodejs composer

systemctl enable --now postgresql redis-server nginx supervisor cron php${PHP_VER}-fpm

# 6. Database
log "Membuat database dan user..."
DB_PASS="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN;
CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}';
EXCEPTION WHEN duplicate_object THEN NULL;
END
\$\$;
SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec
SQL

# 7. Ambil kode
log "Mengklon kode aplikasi..."
install -d -o www-data -g www-data /var/www
if [ -d "$APP_DIR/.git" ]; then
  cd "$APP_DIR"
  sudo -u www-data git fetch origin
  sudo -u www-data git checkout "$BRANCH"
  sudo -u www-data git pull --ff-only origin "$BRANCH"
else
  rm -rf "$APP_DIR"
  sudo -u www-data git clone -b "$BRANCH" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"

# 8. Composer + npm + build
log "Menginstal dependensi PHP & build frontend..."
sudo -u www-data composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
sudo -u www-data npm ci --prefix web
sudo -u www-data npm run build --prefix web

# 9. Environment
log "Menyiapkan .env..."
if [ ! -f .env ]; then
  sudo -u www-data cp .env.production.example .env
fi

# Set nilai dasar
sudo -u www-data sed -i \
  -e "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" \
  -e "s|^DB_HOST=.*|DB_HOST=127.0.0.1|" \
  -e "s|^DB_PORT=.*|DB_PORT=5432|" \
  -e "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" \
  -e "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" \
  -e "s|^REDIS_HOST=.*|REDIS_HOST=127.0.0.1|" \
  -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=|" \
  -e "s|^CACHE_STORE=.*|CACHE_STORE=redis|" \
  -e "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" \
  -e "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|" \
  -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" \
  -e "s|^COOKIE_SECURE=.*|COOKIE_SECURE=1|" \
  .env

# APP_KEY
if ! grep -q '^APP_KEY=base64:' .env >/dev/null 2>&1; then
  sudo -u www-data php artisan key:generate --force
fi

# 10. Migrasi + seed + optimize
log "Migrasi database..."
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force
sudo -u www-data php artisan optimize

# 11. Permission
log "Mengatur izin..."
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod 640 "$APP_DIR/.env"

# 12. Nginx
log "Mengonfigurasi Nginx..."
cat > /etc/nginx/sites-available/se-smart-pulsa <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20m;

    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-Frame-Options "SAMEORIGIN" always;

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/se-smart-pulsa /etc/nginx/sites-enabled/se-smart-pulsa
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

# 13. Supervisor worker
log "Mengonfigurasi queue worker..."
cat > /etc/supervisor/conf.d/se-smart-pulsa-worker.conf <<SUP
[program:se-smart-pulsa-worker]
process_name=%(program_name)s_%(process_num)02d
directory=${APP_DIR}
command=/usr/bin/php ${APP_DIR}/artisan queue:work redis --sleep=3 --tries=3 --timeout=90 --max-time=3600
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=100
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
SUP
supervisorctl reread
supervisorctl update

# 14. Cron scheduler
log "Mengonfigurasi cron scheduler..."
cat > /etc/cron.d/se-smart-pulsa <<CRON
* * * * * www-data cd ${APP_DIR} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 0644 /etc/cron.d/se-smart-pulsa
systemctl restart cron

# Selesai
APP_KEY_VAL=$(grep '^APP_KEY=' .env | cut -d= -f2-)
echo
echo "============================================================"
echo "  INSTALASI SELESAI"
echo "============================================================"
echo "  Domain         : https://${DOMAIN}"
echo "  Direktori app   : ${APP_DIR}"
echo "  Database        : ${DB_NAME}"
echo "  User DB         : ${DB_USER}"
echo "  Password DB     : ${DB_PASS}"
echo "  APP_KEY         : ${APP_KEY_VAL}"
echo "============================================================"
echo
echo "LANGKAH BERIKUTNYA:"
echo "1. Pastikan DNS ${DOMAIN} sudah mengarah ke IP ini, lalu:"
echo "   certbot --nginx -d ${DOMAIN}"
echo
echo "2. Edit secret di ${APP_DIR}/.env untuk mengisi:"
echo "   - MAIL_* (SMTP / Kirisan)"
echo "   - FONNTE_TOKEN"
echo "   - MIDTRANS_SERVER_KEY, MIDTRANS_CLIENT_KEY, MIDTRANS_IS_PRODUCTION"
echo "   - DIGIFLAZZ_USERNAME, DIGIFLAZZ_API_KEY, DIGIFLAZZ_WEBHOOK_SECRET"
echo "   - MIDTRANS_FINISH_URL=https://${DOMAIN}/app"
echo "   Lalu jalankan: php artisan optimize"
echo
echo "3. Sync katalog Digiflazz (setelah kredensial diisi):"
echo "   cd ${APP_DIR} && sudo -u www-data php artisan digiflazz:sync-products"
echo
echo "4. Set password admin:"
echo "   sudo -u www-data php artisan admin:password-link muhammadyusufrifaldi@gmail.com --url=https://${DOMAIN}"
echo
echo "5. Webhook yang harus didaftarkan:"
echo "   Midtrans : https://${DOMAIN}/api/v1/midtrans/notification"
echo "   Digiflazz: https://${DOMAIN}/api/v1/digiflazz/webhook"
echo "============================================================"
