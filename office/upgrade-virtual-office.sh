#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/opt/se-smart-pulsa-kantor"
SERVICE="se-kantor-agent"
BRANCH="build/kantor-agent-ai-installer"
RAW="https://raw.githubusercontent.com/Developer-Nota-service/SE-SMART-PULSA/${BRANCH}/office/virtual-office.html"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Jalankan sebagai root."
  exit 1
fi
if [[ ! -d "$APP_DIR" || ! -f "$APP_DIR/server.py" ]]; then
  echo "Fondasi kantor tidak ditemukan di $APP_DIR. Tidak ada perubahan."
  exit 1
fi
if ! systemctl is-active --quiet "$SERVICE"; then
  echo "Layanan $SERVICE tidak aktif. Periksa dahulu; tidak ada perubahan."
  systemctl status "$SERVICE" --no-pager || true
  exit 1
fi
command -v curl >/dev/null 2>&1 || { echo "curl belum tersedia."; exit 1; }
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
echo "Mengunduh antarmuka Virtual AI Office..."
curl -fL --retry 3 --connect-timeout 15 "$RAW" -o "$TMP"
if ! grep -q "VIRTUAL AI OFFICE" "$TMP" || ! grep -q "/api/agents" "$TMP" || ! grep -q "data-agent" "$TMP"; then
  echo "Validasi file gagal. Antarmuka lama tetap dipertahankan."
  exit 1
fi
STAMP="$(date +%Y%m%d-%H%M%S)"
cp -a "$APP_DIR/index.html" "$APP_DIR/index.html.backup-$STAMP"
install -o root -g kantorai -m 0640 "$TMP" "$APP_DIR/index.html"
if ! systemctl restart "$SERVICE"; then
  echo "Restart gagal. Memulihkan antarmuka sebelumnya..."
  cp -a "$APP_DIR/index.html.backup-$STAMP" "$APP_DIR/index.html"
  systemctl restart "$SERVICE" || true
  exit 1
fi
sleep 2
if ! curl -fsS http://127.0.0.1:3334/api/status >/dev/null; then
  echo "Pemeriksaan API gagal. Memulihkan antarmuka sebelumnya..."
  cp -a "$APP_DIR/index.html.backup-$STAMP" "$APP_DIR/index.html"
  systemctl restart "$SERVICE" || true
  exit 1
fi
if ! curl -fsS http://127.0.0.1:3334/ | grep -q "Kantor Agent AI"; then
  echo "Pemeriksaan halaman gagal. Memulihkan antarmuka sebelumnya..."
  cp -a "$APP_DIR/index.html.backup-$STAMP" "$APP_DIR/index.html"
  systemctl restart "$SERVICE" || true
  exit 1
fi
echo
echo "=== VIRTUAL AI OFFICE BERHASIL DIPASANG ==="
echo "Antarmuka : $APP_DIR/index.html"
echo "Backup    : $APP_DIR/index.html.backup-$STAMP"
echo "Service   : $(systemctl is-active "$SERVICE")"
echo "Alamat    : http://127.0.0.1:3334 (lokal saja)"
echo "SSH, Cloudflare Tunnel, firewall, dan website penjualan tidak diubah."
echo "Catatan   : Hermes/LLM belum diintegrasikan; UI membaca data API fondasi."
