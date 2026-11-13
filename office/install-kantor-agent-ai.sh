#!/usr/bin/env bash
set -Eeuo pipefail

# SE SMART PULSA - Kantor Agent AI bootstrap
# Installs a lightweight local office dashboard and task/event foundation.
# Does NOT modify SSH, cloudflared, existing websites, Nginx, or firewall rules.

APP_DIR="/opt/se-smart-pulsa-kantor"
SERVICE="se-kantor-agent"
APP_USER="kantorai"
PORT="3334"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Jalankan sebagai root: curl ... | sudo bash"
  exit 1
fi

echo "=== SE SMART PULSA | KANTOR AGENT AI ==="
echo "Memeriksa OS dan kapasitas VPS..."
. /etc/os-release
if [[ "${ID:-}" != "ubuntu" ]]; then
  echo "Installer ini disiapkan untuk Ubuntu. Dibatalkan."
  exit 1
fi
if ! command -v python3 >/dev/null 2>&1; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y python3
fi
if ! command -v systemctl >/dev/null 2>&1; then
  echo "systemd tidak ditemukan. Dibatalkan."
  exit 1
fi

echo "Memastikan port lokal $PORT belum dipakai..."
if ss -lnt 2>/dev/null | grep -q ":$PORT "; then
  echo "Port $PORT sedang digunakan. Installer berhenti agar tidak menimpa layanan."
  exit 1
fi

if id "$APP_USER" >/dev/null 2>&1; then
  echo "Akun layanan $APP_USER sudah ada; akan digunakan."
else
  useradd --system --home-dir /var/lib/kantorai --create-home --shell /usr/sbin/nologin "$APP_USER"
fi

if [[ -e "$APP_DIR" ]]; then
  BACKUP="${APP_DIR}.backup.$(date +%Y%m%d%H%M%S)"
  echo "Folder lama ditemukan. Membuat backup: $BACKUP"
  mv "$APP_DIR" "$BACKUP"
fi

install -d -o root -g "$APP_USER" -m 0750 "$APP_DIR"
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 /var/lib/kantorai

cat > "$APP_DIR/server.py" <<'PY'
#!/usr/bin/env python3
import json, os, sqlite3, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

DB = Path("/var/lib/kantorai/office.db")
HOST, PORT = "127.0.0.1", 3334
AGENTS = [
 {"id":"hermes","name":"Hermes","role":"Kepala Koordinator","description":"Menerima instruksi direktur dan membagi pekerjaan","state":"Belum dikonfigurasi"},
 {"id":"monitor","name":"Agent Monitoring","role":"Pemantauan Server & Website","description":"Pemeriksaan uptime, resource, dan log","state":"Menunggu integrasi"},
 {"id":"technician","name":"Agent Teknisi","role":"Diagnosis & Perbaikan","description":"Analisis gangguan dan usulan perbaikan","state":"Menunggu integrasi"},
 {"id":"sales","name":"Agent Penjualan","role":"Operasional PPOB","description":"Pemantauan produk, transaksi, dan layanan","state":"Menunggu integrasi"},
 {"id":"security","name":"Agent Keamanan","role":"Keamanan Sistem","description":"Pemeriksaan konfigurasi dan aktivitas mencurigakan","state":"Menunggu integrasi"},
 {"id":"reports","name":"Agent Laporan","role":"Laporan Operasional","description":"Ringkasan pekerjaan dan laporan direktur","state":"Menunggu integrasi"},
]
def db():
 c=sqlite3.connect(DB); c.row_factory=sqlite3.Row
 c.execute("""CREATE TABLE IF NOT EXISTS tasks(
 id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, detail TEXT DEFAULT '',
 assigned TEXT DEFAULT 'hermes', status TEXT DEFAULT 'queued',
 created INTEGER NOT NULL, updated INTEGER NOT NULL)""")
 c.execute("""CREATE TABLE IF NOT EXISTS events(
 id INTEGER PRIMARY KEY AUTOINCREMENT, agent TEXT, message TEXT NOT NULL,
 level TEXT DEFAULT 'info', created INTEGER NOT NULL)""")
 c.commit(); return c
class Handler(BaseHTTPRequestHandler):
 def send(self, code, body, kind="application/json; charset=utf-8"):
  raw=body if isinstance(body,bytes) else body.encode()
  self.send_response(code); self.send_header("Content-Type",kind)
  self.send_header("Content-Length",str(len(raw)))
  self.send_header("Cache-Control","no-store")
  self.end_headers(); self.wfile.write(raw)
 def do_GET(self):
  if self.path in ("/","/index.html"):
   try: self.send(200,Path("/opt/se-smart-pulsa-kantor/index.html").read_bytes(),"text/html; charset=utf-8")
   except Exception: self.send(500,"Dashboard belum tersedia","text/plain")
   return
  c=db()
  if self.path=="/api/status":
   self.send(200,json.dumps({"ok":True,"office":"SE SMART PULSA KANTOR AGENT AI","mode":"foundation","agents":len(AGENTS),"tasks":c.execute("select count(*) from tasks").fetchone()[0],"uptime":"service-active"}))
  elif self.path=="/api/agents":
   self.send(200,json.dumps(AGENTS))
  elif self.path=="/api/tasks":
   rows=[dict(x) for x in c.execute("select * from tasks order by id desc limit 100")]
   self.send(200,json.dumps(rows))
  elif self.path=="/api/events":
   rows=[dict(x) for x in c.execute("select * from events order by id desc limit 50")]
   self.send(200,json.dumps(rows))
  else: self.send(404,json.dumps({"error":"not_found"}))
 def do_POST(self):
  if self.path!="/api/tasks":
   self.send(404,json.dumps({"error":"not_found"})); return
  try:
   n=int(self.headers.get("Content-Length","0"))
   if n<2 or n>10000: raise ValueError("ukuran data tidak valid")
   data=json.loads(self.rfile.read(n))
   title=str(data.get("title","")).strip()
   detail=str(data.get("detail","")).strip()
   assigned=str(data.get("assigned","hermes"))
   if not title or len(title)>160 or len(detail)>2000: raise ValueError("judul/detail tidak valid")
   if assigned not in {a["id"] for a in AGENTS}: raise ValueError("agen tidak dikenal")
   c=db(); now=int(time.time())
   cur=c.execute("insert into tasks(title,detail,assigned,status,created,updated) values(?,?,?,'queued',?,?)",(title,detail,assigned,now,now))
   c.execute("insert into events(agent,message,level,created) values(?,?,?,?)",("director","Tugas baru dibuat: "+title,"info",now))
   c.commit()
   self.send(201,json.dumps({"ok":True,"id":cur.lastrowid,"status":"queued"}))
  except Exception as e: self.send(400,json.dumps({"error":str(e)}))
 def log_message(self,*args): pass
if __name__=="__main__":
 db().close()
 print("Kantor Agent AI listening on 127.0.0.1:3334",flush=True)
 ThreadingHTTPServer((HOST,PORT),Handler).serve_forever()
PY

cat > "$APP_DIR/index.html" <<'HTML'
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SE SMART PULSA — Kantor Agent AI</title>
<style>
:root{color-scheme:dark;--bg:#09111f;--panel:#111d30;--line:#24344d;--muted:#9aabc3;--cyan:#38d6cf;--blue:#638cff}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 15% 0%,#15294a,var(--bg) 42%);color:#eef5ff;font:15px system-ui,Segoe UI,sans-serif}
header{padding:22px max(18px,calc((100% - 1180px)/2));border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.brand{font-weight:800;letter-spacing:.08em}.brand span{color:var(--cyan)}.sub{color:var(--muted);font-size:13px;margin-top:5px}
main{max-width:1180px;margin:24px auto;padding:0 18px}.hero{background:linear-gradient(120deg,#142b49,#14223a 60%,#153b49);border:1px solid #2d5268;border-radius:18px;padding:22px;margin-bottom:18px}
.hero h1{margin:0 0 8px;font-size:clamp(23px,4vw,34px)}.hero p{color:#c3d3e8;margin:0;max-width:760px;line-height:1.6}
.stats,.agents{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:13px;margin:16px 0}.card{background:var(--panel);border:1px solid var(--line);border-radius:15px;padding:16px}.k{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}.v{font-size:25px;font-weight:750;margin-top:5px}
.agent{min-height:142px}.agent h3{margin:8px 0 4px}.agent p{margin:0;color:var(--muted);font-size:13px;line-height:1.5}.state{display:inline-block;color:#ffd68a;background:#49391e;border-radius:20px;padding:4px 9px;font-size:11px;margin-top:12px}
section{margin:24px 0}h2{font-size:19px}.layout{display:grid;grid-template-columns:1.2fr .8fr;gap:14px}@media(max-width:760px){.layout{grid-template-columns:1fr}}
input,textarea,select,button{width:100%;border:1px solid var(--line);border-radius:10px;padding:11px;background:#0b1728;color:#eef5ff;font:inherit;margin:5px 0}
button{background:linear-gradient(90deg,#246f8d,#3156a8);border:0;font-weight:700;cursor:pointer}button:hover{filter:brightness(1.15)}
.task{padding:12px 0;border-bottom:1px solid var(--line)}.task:last-child{border:0}.muted{color:var(--muted);font-size:12px}.notice{border:1px solid #6b5529;background:#2a2418;color:#f6d99d;padding:13px;border-radius:12px;line-height:1.55}
footer{color:var(--muted);font-size:12px;padding:20px 0 35px}
</style></head><body>
<header><div><div class="brand">SE SMART <span>PULSA</span></div><div class="sub">Kantor Agent AI · Virtual Operations Center</div></div><div class="sub">DIREKTUR: ANDA　|　STATUS: FONDASI TERPASANG</div></header>
<main>
<div class="hero"><h1>Selamat datang di Kantor Agent AI</h1><p>Ruang kendali operasional SE SMART PULSA. Hermes dirancang sebagai kepala koordinator yang menerima instruksi direktur, membagi tugas kepada agen spesialis, memantau hasil, dan melaporkan pekerjaan.</p></div>
<div class="stats"><div class="card"><div class="k">Sistem kantor</div><div class="v" id="sys">Memeriksa…</div></div><div class="card"><div class="k">Agen terdaftar</div><div class="v" id="ac">6</div></div><div class="card"><div class="k">Tugas tersimpan</div><div class="v" id="tc">0</div></div></div>
<section><h2>Ruang kerja agen</h2><div class="agents" id="agents"></div></section>
<div class="layout"><section class="card"><h2>Berikan tugas kepada kantor</h2><form id="taskform"><input id="title" maxlength="160" placeholder="Judul tugas, misalnya: Periksa kesehatan server" required><textarea id="detail" maxlength="2000" rows="3" placeholder="Detail dan hasil yang diharapkan"></textarea><select id="assigned"><option value="hermes">Hermes — Koordinator</option><option value="monitor">Agent Monitoring</option><option value="technician">Agent Teknisi</option><option value="sales">Agent Penjualan</option><option value="security">Agent Keamanan</option><option value="reports">Agent Laporan</option></select><button type="submit">Simpan tugas ke antrean</button></form><div class="muted" id="msg">Tugas disimpan lokal. Eksekusi AI belum diaktifkan.</div></section>
<section class="card"><h2>Papan tugas</h2><div id="tasks" class="muted">Memuat tugas…</div></section></div>
<section class="notice"><b>Status pembangunan:</b> Ini adalah fondasi dashboard dan antrean tugas, bukan agen AI otonom yang sudah aktif. Koneksi Hermes, penyedia model AI, integrasi monitoring server, dan izin tindakan akan dipasang serta diuji pada tahap berikutnya. Website penjualan dan layanan SSH/Cloudflare tidak diubah oleh installer ini.</section>
<footer>SE SMART PULSA · Kantor Agent AI · Lokal: 127.0.0.1:3334 · Data tugas tersimpan di SQLite.</footer>
</main>
<script>
const $=id=>document.getElementById(id);
async function load(){try{
const [s,a,t]=await Promise.all(['/api/status','/api/agents','/api/tasks'].map(u=>fetch(u).then(r=>r.json())));
$('sys').textContent=s.ok?'ONLINE':'OFFLINE';$('ac').textContent=a.length;$('tc').textContent=s.tasks;
$('agents').innerHTML=a.map(x=>'<div class="card agent"><div class="muted">'+x.role+'</div><h3>'+x.name+'</h3><p>'+x.description+'</p><span class="state">'+x.state+'</span></div>').join('');
$('tasks').innerHTML=t.length?t.map(x=>'<div class="task"><b>#'+x.id+' '+esc(x.title)+'</b><div class="muted">Penanggung jawab: '+esc(x.assigned)+' · Status: '+esc(x.status)+'</div><div>'+esc(x.detail)+'</div></div>').join(''):'Belum ada tugas. Buat tugas pertama di formulir.';
}catch(e){$('sys').textContent='CEK LAYANAN';}}
function esc(s){return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
$('taskform').addEventListener('submit',async e=>{e.preventDefault();$('msg').textContent='Menyimpan…';let r=await fetch('/api/tasks',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title:$('title').value,detail:$('detail').value,assigned:$('assigned').value})});let j=await r.json();$('msg').textContent=r.ok?'Tugas #'+j.id+' tersimpan di antrean. Belum dieksekusi AI.':(j.error||'Gagal menyimpan');if(r.ok){$('taskform').reset();load();}});
load();setInterval(load,15000);
</script></body></html>
HTML

chown -R root:"$APP_USER" "$APP_DIR"
chmod 0750 "$APP_DIR"
chmod 0640 "$APP_DIR/server.py" "$APP_DIR/index.html"

cat > "/etc/systemd/system/$SERVICE.service" <<UNIT
[Unit]
Description=SE SMART PULSA Kantor Agent AI Dashboard
After=network.target
Wants=network.target

[Service]
Type=simple
User=$APP_USER
Group=$APP_USER
WorkingDirectory=$APP_DIR
ExecStart=/usr/bin/python3 $APP_DIR/server.py
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictSUIDSGID=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
ReadWritePaths=/var/lib/kantorai
MemoryMax=350M
CPUQuota=60%
TasksMax=64
UMask=0027

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now "$SERVICE"
sleep 2

if ! systemctl is-active --quiet "$SERVICE"; then
  echo "Layanan gagal aktif. Log terakhir:"
  journalctl -u "$SERVICE" -n 50 --no-pager
  exit 1
fi

if ! curl -fsS "http://127.0.0.1:$PORT/api/status" >/dev/null; then
  echo "Pemeriksaan HTTP gagal. Log terakhir:"
  journalctl -u "$SERVICE" -n 50 --no-pager
  exit 1
fi

echo
echo "=== INSTALASI FONDASI KANTOR BERHASIL ==="
echo "Service : $SERVICE"
echo "Folder  : $APP_DIR"
echo "Port    : 127.0.0.1:$PORT (hanya lokal, belum dipublikasikan)"
echo "Status  : $(systemctl is-active "$SERVICE")"
echo
echo "SSH dan Cloudflare Tunnel tidak diubah."
echo "Website sesmartpulsa.my.id tidak diubah."
echo "PENTING: Hermes/LLM dan agen otonom belum dihubungkan; ini tahap fondasi dashboard + antrean tugas."
echo "Cek status: systemctl status $SERVICE --no-pager"
echo "Log       : journalctl -u $SERVICE -f"
