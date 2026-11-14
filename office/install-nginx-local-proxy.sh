#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="/opt/se-smart-pulsa-kantor"
APP_HTML="$APP_DIR/index.html"
NGINX_SITE="/etc/nginx/sites-available/se-smart-pulsa-kantoragent"
NGINX_LINK="/etc/nginx/sites-enabled/se-smart-pulsa-kantoragent"
LOCAL_PORT=8080
[[ "$EUID" -eq 0 ]] || { echo "Jalankan sebagai root."; exit 1; }
systemctl is-active --quiet se-kantor-agent || { echo "Service kantor tidak aktif."; exit 1; }
[[ -s "$APP_HTML" ]] || { echo "File HTML kantor tidak ditemukan."; exit 1; }
if ss -lnt | grep -qE "127\\.0\\.0\\.1:${LOCAL_PORT}[[:space:]]"; then echo "Port 8080 sudah digunakan."; exit 1; fi

echo "=== Memasang Nginx reverse proxy lokal ==="
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx
STAMP="$(date +%Y%m%d-%H%M%S)"
cp -a "$APP_HTML" "$APP_HTML.backup-nginx-$STAMP"
if ! grep -q "/kantoragent/api/status" "$APP_HTML"; then
  sed -i "s#'/api/#'/kantoragent/api/#g" "$APP_HTML"
fi
cat > "$NGINX_SITE" <<'NGINX'
server {
    listen 127.0.0.1:8080;
    server_name localhost;
    access_log /var/log/nginx/se-kantor-agent.access.log;
    error_log /var/log/nginx/se-kantor-agent.error.log;
    location = /kantoragent { return 301 /kantoragent/; }
    location ^~ /kantoragent/ {
        proxy_pass http://127.0.0.1:3334/;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 5s;
        proxy_read_timeout 30s;
    }
    location / { return 404; }
}
NGINX
ln -sfn "$NGINX_SITE" "$NGINX_LINK"
nginx -t
systemctl enable nginx
systemctl restart nginx
sleep 1
if ! curl -fsS --max-time 5 http://127.0.0.1:8080/kantoragent/ >/dev/null; then
  echo "Uji halaman gagal; memulihkan HTML."
  cp -a "$APP_HTML.backup-nginx-$STAMP" "$APP_HTML"
  rm -f "$NGINX_LINK" "$NGINX_SITE"
  nginx -t && systemctl restart nginx || true
  exit 1
fi
STATUS="$(curl -fsS --max-time 5 http://127.0.0.1:8080/kantoragent/api/status)"
echo
echo "=== NGINX LOKAL BERHASIL DIPASANG ==="
echo "Nginx         : $(systemctl is-active nginx)"
echo "Kantor Agent  : $(systemctl is-active se-kantor-agent)"
echo "URL lokal     : http://127.0.0.1:8080/kantoragent/"
echo "API status    : $STATUS"
echo "Backup HTML   : $APP_HTML.backup-nginx-$STAMP"
echo
echo "Keamanan: Nginx hanya mendengarkan 127.0.0.1:8080; belum dipublikasikan."
echo "SSH, Cloudflare Tunnel, DNS, firewall, dan website utama tidak diubah."
