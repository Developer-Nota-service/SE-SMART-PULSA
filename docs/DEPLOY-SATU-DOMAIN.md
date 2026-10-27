# Deploy satu domain (React + API dalam satu app)

Satu repo = satu app = satu domain. Tidak butuh Laravel maupun Termux.
Satu proses Node menyajikan UI React dan API:

- `https://domainmu.com/` … `/transaksi` → React (build `web/dist`)
- `https://domainmu.com/api/v1/...` → API Express (`api/`)
- Database: Postgres milik platform; schema + seed demo dibuat otomatis saat start
  (`api/src/setup-db.js`, idempotent — aman dijalankan setiap boot).

## 1. Deploy lewat platform (pilih Node.js)

| Setelan | Nilai |
| --- | --- |
| Framework | **Node.js** (bukan Laravel/React/Static) |
| Root directory | `/` |
| Build command | `npm run build` |
| Start command | `npm start` |

`npm run build` (= `npm --prefix web ci && npm --prefix web run build && npm --prefix api ci --omit=dev`)
dan `npm start` (= `npm --prefix api run setup && npm --prefix api start`) ada di
`package.json` root.

Environment variable:

```
DATABASE_URL=postgres://USER:PASSWORD@HOST:5432/DBNAME
COOKIE_SECURE=1
PORT=8000          # biasanya diisi platform
```

Tambahkan database Postgres dari dashboard platform, lalu salin `DATABASE_URL`-nya.
Jangan pakai SQLite — file-nya hilang setiap deploy.

Catatan: `PORT` harus dipakai apa adanya (server membaca `process.env.PORT`);
jika platform meneruskan port lewat argumen (`$PORT`), start command menjadi
`npm --prefix api run setup && node api/src/server.js`.

## 2. Deploy lewat Docker (VPS/Railway/Fly, dsb.)

`Dockerfile` di root membangun React lalu menjalankannya dari satu proses Node:

```bash
docker build -t se-smart-pulsa .
docker run -d -p 8000:8000 \
  -e DATABASE_URL='postgres://USER:PASSWORD@HOST:5432/DBNAME' \
  -e COOKIE_SECURE=1 \
  se-smart-pulsa
```

Letakkan di belakang reverse proxy HTTPS (Caddy/nginx/Traefik) yang meneruskan
`domainmu.com` → `127.0.0.1:8000`.

## 3. Cek setelah deploy

1. `https://domainmu.com/api/v1/me` → `{"message":"Sesi tidak ditemukan..."}` (401) = API hidup.
2. `https://domainmu.com/` → halaman login tampil; `https://domainmu.com/transaksi`
   juga tampil (bukan 404) berkat fallback SPA.
3. Login akun demo `demo@sesmartpulsa.id` / `demo12345` → Beranda menampilkan saldo
   Rp186.900 dan 95 produk demo.
4. Daftar akun baru → masuk → saldo Rp0 tampil.

## 4. Kalau nanti tetap mau pindah ke Laravel

Kontrak API-nya sudah ditulis di `AGENTS.md`; port endpoint dari `api/src/routes/`
ke Laravel, arahkan `web` ke backend itu (base URL `/api/v1`), dan setelan deploy
tinggal mengikuti framework Laravel.
