#!/usr/bin/env bash
set -Eeuo pipefail

# Kantor Agent AI — pemasang bridge di VPS (Debian/Ubuntu, systemd).
#
#   sudo bash office/install-kantor-bridge.sh [opsi]
#
# Opsi:
#   --port <n>                Port lokal bridge (default 3334)
#   --workspace <dir>         Folder kerja Claude Code (default /srv/kantor-workspace)
#   --enable-run              Izinkan bridge menjalankan `claude -p` untuk tugas
#   --claude-cmd <perintah>   Perintah Claude (default: claude)
#   --domain <domain>         Buat vhost Nginx untuk domain ini (HTTP -> proxy)
#   --install-claude-hooks    Pasang hook Claude Code untuk pengguna sudo
#   --ref <git-ref>           Ref repo untuk unduhan (default main)
#
# Installer tidak menyentuh SSH, firewall, atau website lain. Semua perubahan
# hanya: user layanan, folder /opt/kantor-office, /var/lib/kantor-office,
# unit systemd kantor-office, dan (bila --domain) satu file vhost Nginx.

APP_DIR="/opt/kantor-office"
SERVICE="kantor-office"
APP_USER="kantor"
CONF_DIR="/etc/kantor-office"
STATE_DIR="/var/lib/kantor-office"
PORT="3334"
WORKSPACE="/srv/kantor-workspace"
ENABLE_RUN="0"
CLAUDE_CMD="claude"
DOMAIN=""
INSTALL_HOOKS="0"
REF="main"
REPO_URL="https://github.com/Developer-Nota-service/SE-SMART-PULSA.git"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port) PORT="${2:?nilai --port wajib}"; shift 2 ;;
    --workspace) WORKSPACE="${2:?nilai --workspace wajib}"; shift 2 ;;
    --enable-run) ENABLE_RUN="1"; shift ;;
    --claude-cmd) CLAUDE_CMD="${2:?nilai --claude-cmd wajib}"; shift 2 ;;
    --domain) DOMAIN="${2:?nilai --domain wajib}"; shift 2 ;;
    --install-claude-hooks) INSTALL_HOOKS="1"; shift ;;
    --ref) REF="${2:?nilai --ref wajib}"; shift 2 ;;
    -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
    *) echo "Opsi tidak dikenal: $1"; exit 1 ;;
  esac
done

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Jalankan sebagai root: sudo bash office/install-kantor-bridge.sh"
  exit 1
fi

if [[ ! -r /etc/os-release ]]; then
  echo "Distribusi tidak dikenali (butuh Debian/Ubuntu)."; exit 1
fi
. /etc/os-release
case "${ID:-}" in
  debian|ubuntu) ;;
  *) echo "Installer ini disiapkan untuk Debian/Ubuntu. Dibatalkan."; exit 1 ;;
esac

if ! command -v systemctl >/dev/null 2>&1; then
  echo "systemd tidak ditemukan. Dibatalkan."; exit 1
fi

echo "=== KANTOR AGENT AI · BRIDGE VPS ==="

# --- Node.js >= 18 --------------------------------------------------------
node_ok() {
  command -v node >/dev/null 2>&1 || return 1
  local major
  major="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
  [[ "$major" -ge 18 ]]
}

if ! node_ok; then
  echo "Node.js >= 18 belum ada. Mencoba memasang nodejs dari apt…"
  apt-get update -qq || true
  DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs >/dev/null 2>&1 || true
  if ! node_ok; then
    cat <<'EOF'
Node.js >= 18 tidak tersedia di repo apt sistem ini.
Pasang dulu Node.js 22 dari NodeSource, lalu jalankan installer lagi:

  curl -fsSL https://deb.nodesource.com/setup_22.x | sudo bash -
  sudo apt-get install -y nodejs
EOF
    exit 1
  fi
fi
echo "Node.js: $(node -v)"

# --- berkas bridge --------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="${SCRIPT_DIR}/bridge"
CLEANUP=""
if [[ ! -f "${SRC_DIR}/agent-office-bridge.mjs" ]]; then
  echo "Berkas bridge tidak ada di samping installer; mengunduh dari ${REPO_URL} (${REF})…"
  TMP_DIR="$(mktemp -d)"
  CLEANUP="$TMP_DIR"
  if ! command -v git >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y git >/dev/null 2>&1 || true
  fi
  git clone --depth 1 --branch "$REF" "$REPO_URL" "$TMP_DIR/repo" >/dev/null 2>&1 \
    || { echo "Gagal mengunduh repo. Salin folder office/ dari repo secara manual."; exit 1; }
  SRC_DIR="$TMP_DIR/repo/office/bridge"
fi
trap '[[ -n "$CLEANUP" ]] && rm -rf "$CLEANUP"' EXIT

if id "$APP_USER" >/dev/null 2>&1; then
  echo "User layanan ${APP_USER} sudah ada."
else
  useradd --system --home-dir "$STATE_DIR" --create-home --shell /usr/sbin/nologin "$APP_USER"
fi

install -d -o root -g root -m 0755 "$APP_DIR"
install -d -m 0750 "$CONF_DIR"
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$STATE_DIR"

install -m 0644 "${SRC_DIR}/agent-office-bridge.mjs" "${APP_DIR}/agent-office-bridge.mjs"
install -m 0644 "${SRC_DIR}/claude-hook.mjs" "${APP_DIR}/claude-hook.mjs"
install -m 0755 "${SRC_DIR}/install-claude-hooks.mjs" "${APP_DIR}/install-claude-hooks.mjs"

# --- workspace ------------------------------------------------------------
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$WORKSPACE"

# --- konfigurasi & token --------------------------------------------------
ENV_FILE="${CONF_DIR}/bridge.env"
if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  EXISTING_TOKEN="$(grep -E '^KANTOR_TOKEN=' "$ENV_FILE" | head -1 | cut -d= -f2- || true)"
  # Setelan eksekusi yang sudah aktif tidak dimatikan oleh pemasangan ulang
  # tanpa --enable-run.
  if [[ "$ENABLE_RUN" != "1" ]] && grep -q '^KANTOR_ALLOW_RUN=1$' "$ENV_FILE"; then
    ENABLE_RUN="1"
  fi
else
  EXISTING_TOKEN=""
fi
if [[ -z "${EXISTING_TOKEN}" ]]; then
  if command -v openssl >/dev/null 2>&1; then
    TOKEN="$(openssl rand -hex 24)"
  else
    TOKEN="$(node -e 'console.log(require("node:crypto").randomBytes(24).toString("hex"))')"
  fi
else
  TOKEN="$EXISTING_TOKEN"
fi

umask 077
cat > "$ENV_FILE" <<EOF
KANTOR_PORT=${PORT}
KANTOR_HOST=127.0.0.1
KANTOR_TOKEN=${TOKEN}
KANTOR_STATE_DIR=${STATE_DIR}
KANTOR_WORKSPACE=${WORKSPACE}
KANTOR_ALLOW_RUN=${ENABLE_RUN}
KANTOR_CLAUDE_CMD=${CLAUDE_CMD}
KANTOR_CLAUDE_ARGS=
EOF
chmod 0600 "$ENV_FILE"
umask 022

# --- unit systemd ---------------------------------------------------------
cat > "/etc/systemd/system/${SERVICE}.service" <<EOF
[Unit]
Description=Kantor Agent AI bridge
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${APP_USER}
WorkingDirectory=${APP_DIR}
EnvironmentFile=${ENV_FILE}
ExecStart=/usr/bin/env node ${APP_DIR}/agent-office-bridge.mjs
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${STATE_DIR} ${WORKSPACE}
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable "$SERVICE" >/dev/null
systemctl restart "$SERVICE"

echo "Menunggu bridge siap di 127.0.0.1:${PORT}…"
healthy() {
  node -e "fetch('http://127.0.0.1:${PORT}/health').then((r) => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))" >/dev/null 2>&1
}
for _ in $(seq 1 20); do
  if healthy; then
    break
  fi
  sleep 1
done
if ! healthy; then
  echo "Bridge belum menjawab. Periksa: journalctl -u ${SERVICE} -n 40 --no-pager"
  exit 1
fi
echo "Bridge aktif."

# --- vhost Nginx opsional -------------------------------------------------
if [[ -n "$DOMAIN" ]]; then
  if ! command -v nginx >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y nginx >/dev/null 2>&1 || true
  fi
  if command -v nginx >/dev/null 2>&1; then
    VHOST="/etc/nginx/sites-available/kantor-office"
    cat > "$VHOST" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    client_max_body_size 1m;

    location / {
        proxy_pass http://127.0.0.1:${PORT};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 600s;
    }
}
EOF
    ln -sf "$VHOST" /etc/nginx/sites-enabled/kantor-office
    if nginx -t; then
      systemctl reload nginx || true
      echo "Vhost Nginx dibuat untuk ${DOMAIN}."
      echo "Pasang HTTPS: sudo apt-get install -y certbot python3-certbot-nginx && sudo certbot --nginx -d ${DOMAIN}"
    else
      echo "Konfigurasi Nginx gagal diuji; vhost dibiarkan nonaktif."
      rm -f /etc/nginx/sites-enabled/kantor-office
    fi
  else
    echo "Nginx tidak tersedia; lewati pembuatan vhost."
  fi
fi

# --- hook Claude Code opsional -------------------------------------------
if [[ "$INSTALL_HOOKS" == "1" ]]; then
  TARGET_USER="${SUDO_USER:-}"
  if [[ -z "$TARGET_USER" || "$TARGET_USER" == "root" ]]; then
    echo "Tidak bisa mengenali pengguna non-root untuk memasang hook Claude Code."
    echo "Jalankan sebagai user tersebut: node ${APP_DIR}/install-claude-hooks.mjs"
  else
    TARGET_HOME="$(getent passwd "$TARGET_USER" | cut -d: -f6)"
    USER_CONF="${TARGET_HOME}/.config/kantor-office"
    install -d -o "$TARGET_USER" -g "$(id -gn "$TARGET_USER")" -m 0700 "$USER_CONF"
    cat > "${USER_CONF}/bridge.env" <<EOF
KANTOR_BRIDGE_URL=http://127.0.0.1:${PORT}
KANTOR_TOKEN=${TOKEN}
EOF
    chown "$TARGET_USER":"$(id -gn "$TARGET_USER")" "${USER_CONF}/bridge.env"
    chmod 0600 "${USER_CONF}/bridge.env"
    if sudo -u "$TARGET_USER" node "${APP_DIR}/install-claude-hooks.mjs"; then
      echo "Hook Claude Code terpasang untuk ${TARGET_USER}."
    else
      echo "Pemasangan hook gagal; jalankan manual sebagai ${TARGET_USER}."
    fi
  fi
fi

HOST_HINT="<domain-atau-ip-vps>"
if [[ -n "$DOMAIN" ]]; then HOST_HINT="$DOMAIN"; fi

cat <<EOF

=== SELESAI ===
Bridge      : http://127.0.0.1:${PORT} (hanya lokal)
Token       : ${TOKEN}
State       : ${STATE_DIR}
Workspace   : ${WORKSPACE}
Eksekusi    : $([[ "$ENABLE_RUN" == "1" ]] && echo "aktif (${CLAUDE_CMD})" || echo "mati — tambahkan --enable-run")
Service     : systemctl status ${SERVICE}

Langkah berikutnya:
1. Buka aplikasi Kantor Agent AI di HP → Setelan → isi alamat bridge
   https://${HOST_HINT}  dan token di atas.
2. (Opsional) izinkan eksekusi tugas:
   sudo bash office/install-kantor-bridge.sh --enable-run
   Pastikan user ${APP_USER} sudah login Claude Code:
   sudo -u ${APP_USER} -H ${CLAUDE_CMD} login
3. (Opsional) tampilkan aktivitas sesi Claude Code di kantor:
   node ${APP_DIR}/install-claude-hooks.mjs
   atau ulangi installer dengan --install-claude-hooks
EOF
