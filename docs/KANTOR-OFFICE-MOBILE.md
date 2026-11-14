# Kantor Agent AI — aplikasi HP + bridge VPS

Kantor virtual bergaya pixel-art (mirip proyek `W17ant/Claude-Office`) untuk
memantau dan memberi tugas kepada sesi **Claude Code** yang berjalan di VPS.
Terbuka dari HP, bisa dipasang ke layar utama (PWA), dan tersambung ke VPS lewat
token bridge.

## Bagian

| Bagian | Lokasi | Jalan di |
| --- | --- | --- |
| Aplikasi HP (PWA) | `web/public/kantor/` | Vite dev `/kantor/`, produksi `/app/kantor/` |
| Bridge VPS | `office/bridge/` | systemd di VPS |
| Installer VPS | `office/install-kantor-bridge.sh` | VPS Debian/Ubuntu |
| Hook Claude Code | `office/bridge/claude-hook.mjs`, `install-claude-hooks.mjs` | VPS, user pemilik Claude |
| Generator ikon | `office/tools/generate-kantor-icons.mjs` | lokal |

## 1. Memasang aplikasi di HP

- Di sandbox/preview: buka `/kantor/`.
- Di produksi: buka `https://<domain>/kantor` (dialihkan ke `/app/kantor/`).
- Android/Chrome: menu → **Instal aplikasi**. iPhone/Safari: **Bagikan →
  Tambahkan ke Layar Utama**. Aplikasi tampil layar penuh (standalone), dengan
  service worker network-first untuk tampilan offline.

Tanpa bridge, aplikasi berjalan di **mode demo** (simulasi lokal, ditandai pill
`DEMO`). Mode demo tidak menjalankan perintah apa pun.

## 2. Memasang bridge di VPS

Bridge adalah proses Node tanpa dependensi (`office/bridge/agent-office-bridge.mjs`)
yang menyimpan agen, tugas, dan aktivitas, menerima hook Claude Code, dan — bila
diizinkan — menjalankan `claude -p` di workspace.

```bash
git clone --depth 1 https://github.com/Developer-Nota-service/SE-SMART-PULSA.git
cd SE-SMART-PULSA
sudo bash office/install-kantor-bridge.sh --domain kantor.domainanda.com
```

Yang dibuat installer:

- user layanan `kantor`, folder `/opt/kantor-office`, state `/var/lib/kantor-office`;
- `/etc/kantor-office/bridge.env` (mode 600) berisi token acak dan setelan;
- unit systemd `kantor-office` (bind `127.0.0.1:3334`, `Restart=on-failure`);
- opsional vhost Nginx (HTTP; lanjutkan dengan `certbot --nginx`).

Installer tidak menyentuh SSH, firewall, atau situs lain. Token dicetak di akhir.

### Mengaktifkan eksekusi Claude Code

```bash
sudo bash office/install-kantor-bridge.sh --enable-run --workspace /srv/proyek
sudo -u kantor -H claude login      # atau set ANTHROPIC_API_KEY untuk user kantor
```

Tanpa `--enable-run`, bridge tetap menampilkan status dan menyimpan tugas, tetapi
tombol "Jalankan sekarang" menjawab `runner_mati`.

### Menampilkan sesi Claude Code (hook)

```bash
node office/bridge/install-claude-hooks.mjs          # sebagai user pemilik Claude
# atau: sudo bash office/install-kantor-bridge.sh --install-claude-hooks
```

Hook `SessionStart`, `UserPromptSubmit`, `PreToolUse`, `PostToolUse`,
`Notification`, `Stop`, `SessionEnd` dikirim ke bridge. Hook tidak pernah
menggagalkan sesi Claude (selalu exit 0, timeout 3 detik) dan tidak menghapus hook
lain yang sudah ada di `~/.claude/settings.json`.

## 3. Menyambungkan dari HP

Setelan → isi **alamat bridge** (`https://kantor.domainanda.com`) dan **token**.
Tombol "Uji & simpan" memanggil `GET /api/state` dengan Bearer token.

API bridge (semua `/api/*` wajib `Authorization: Bearer <token>`):

- `GET /health` — liveness (tanpa token).
- `GET /api/state` — agen, tugas, event, statistik.
- `POST /api/tasks` `{title, detail, agent}` — tambah tugas.
- `POST /api/tasks/{id}/run` — jalankan tugas (409 bila runner mati).
- `POST /api/agents/{id}/prompt` `{prompt}` — kirim perintah sebagai tugas.
- `POST /api/events` `{agent, message, level}` — catat aktivitas.
- `POST /api/hooks/claude` — payload hook Claude Code.

## Keamanan

- Token acak 48 hex, dibandingkan constant-time; bind default `127.0.0.1`.
- CORS hanya mengizinkan header `Authorization`/`Content-Type`; app memakai Bearer,
  bukan cookie, jadi tidak ada CSRF lintas situs.
- Body maksimal 64 KB, rate limit 240 permintaan/menit per IP.
- Runner hanya menjalankan perintah tetap (`KANTOR_CLAUDE_CMD`) dengan prompt
  sebagai satu argumen — tidak ada shell dan tidak ada perintah bebas dari API.
- Pakai HTTPS (Nginx + Let's Encrypt) sebelum membuka bridge ke internet; atau
  akses lewat SSH tunnel `ssh -L 3334:127.0.0.1:3334 user@vps`.

## Sandbox Base44

`docker-compose.base44.yml` menjalankan `office-bridge` di port host 3334 dengan
token `base44_sandbox_bridge_token` dan `KANTOR_CLAUDE_CMD=/bin/echo`, karena
sandbox tidak punya Claude CLI. Ini hanya untuk menguji alur aplikasi → bridge →
tugas selesai; di VPS gunakan installer di atas.

## Ikon

```bash
node office/tools/generate-kantor-icons.mjs   # menulis web/public/kantor/icon-*.png
```
