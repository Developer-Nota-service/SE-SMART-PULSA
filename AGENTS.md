# AGENTS.md — SE SMART PULSA

PPOB demo app. Tahap 1: UI + backend demo lokal yang meniru kontrak Laravel.
Backend Laravel lama milik pengguna TIDAK ada di repo ini dan tidak boleh diubah dari sini.

## Menjalankan

`docker-compose.base44.yml` menjalankan semuanya dari source (bind mount, dev server):

- `web` — Vite dev server, host port **3000** → 5173. Entry preview.
- `api` — Express di port internal 8000 (`node --watch`).
- `db` — Postgres 16, volume `pgdata`.
- `migrate` — one-shot: buat schema + seed demo. API menunggu service ini exit 0.

Browser hanya bicara ke port 3000; Vite mem-proxy `/api` ke `http://api:8000`, jadi
single-origin dan tidak perlu CORS. Jangan buka 8000 ke publik.

Setelah mengubah `package.json`, **restart service terkait** — `npm install` hanya jalan
saat container start, bukan saat file berubah:

```bash
docker compose -f docker-compose.base44.yml restart web   # atau api
```

## Kontrak API (v1)

Semua di bawah `/api/v1`. Respons sukses `{ "data": ... }`; error
`{ "message": "...", "errors": { field: [ ... ] } }` mengikuti gaya Laravel.

- `POST /register` `{name,email,phone,password}` → 201
- `POST /login` `{email,password}` → 200, set cookie `sesp_session` (httpOnly, SameSite=Lax)
- `GET /me` → `{ user, lastLoginAt }`
- `POST /logout`
- `GET /products?category=` · `GET /products/:id`
- `GET /transactions?status=&category=&from=&to=` · `GET /transactions/:id`
- `POST /transactions` `{productId,customerNumber}` + header opsional `Idempotency-Key`
- `GET /balance/entries` (ledger) · `GET /deposits` · `POST /deposits` `{amount}`

Akun demo: `demo@sesmartpulsa.id` / `demo12345`. Admin (untuk Tahap 6):
`admin@sesmartpulsa.id` / `admin12345`. Data demo: saldo Rp186.900, 4 transaksi
(success/pending/failed), 4 deposit (paid/pending/expired), 95 produk DEMO di 7 kategori.

## Invariant keamanan (jangan dilanggar)

- Harga dan saldo ditentukan server. Balance check berjalan di SQL
  (`UPDATE users SET balance = balance - $1 WHERE balance >= $1`) karena BIGINT
  dikembalikan Postgres sebagai teks — jangan bandingkan di JS.
- `POST /deposits` hanya membuat status `pending`. **Tidak ada** endpoint yang bisa
  menandai deposit `paid`; itu tugas notifikasi Midtrans yang diverifikasi di Laravel.
  Jangan tambahkan tombol "tandai lunas" di frontend.
- Setiap perubahan saldo menulis `balance_entries` (audit trail) dalam transaksi DB yang sama.
- Password di-hash bcrypt; login dibatasi 5 percobaan gagal / 15 menit per email.
- Token sesi hanya di cookie httpOnly (bukan localStorage). `COOKIE_SECURE=1` untuk
  deployment HTTPS khusus.

## Menyambung ke backend Laravel (Tahap 2+)

`web/src/lib/api.js` adalah satu-satunya lapisan HTTP: base URL dari
`VITE_API_BASE_URL` (default `/api/v1`), `credentials: 'include'`, envelope `data`
di-unwrap, error 401 memicu event `sesp:unauthorized`, 422 diteruskan sebagai
`error.errors` per field. Selama kontrak respons Laravel sama, cukup ganti base URL
dan arahkan cookie/sanctum.

### Autentikasi Bearer (backend Laravel pengguna)

Backend Laravel pengguna memakai Sanctum **personal access token**, bukan cookie:
`POST /login` dan `/register` mengembalikan `token`, lalu setiap request mengirim
`Authorization: Bearer <token>`. `web/src/lib/api.js` menyimpan token itu di
`sessionStorage` (bukan localStorage) dan tetap memakai cookie bila respons tidak
berisi token — jadi API demo tidak berubah. `/register` mewajibkan field
`password_confirmation`; field `phone` opsional dan boleh tidak ada di respons
(kartu saldo tidak menampilkan nomor bila kosong).

### Mengarahkan ke backend Laravel lewat proxy dev

Jangan pernah set `VITE_API_BASE_URL` ke URL tunnel: browser akan memanggil lintas
origin sehingga cookie sesi `SameSite` tidak terkirim. Route tetap same-origin:
browser → port 3000 → proxy Vite → backend.

```bash
# tunnel harus hidup; quick tunnel dapat nama acak baru setiap `cloudflared` dijalankan
LARAVEL_API_ORIGIN=https://xxxx.trycloudflare.com \
  docker compose -f docker-compose.base44.yml up -d --force-recreate web
```

`LARAVEL_API_ORIGIN` kosong/tidak diset → `/api` tetap ke demo lokal (`api:8000`),
jadi preview tidak pernah rusak karena tunnel mati. Cek dulu kesiapannya:

```bash
curl -s -o /dev/null -w "%{http_code}\n" localhost:3000/api/v1/me   # 401 = Laravel menjawab
```

Belum teruji terhadap backend nyata: bila Laravel memasang atribut `Domain` pada
`Set-Cookie` (`SESSION_DOMAIN`), cookie akan ditolak browser di origin preview —
kosongkan `SESSION_DOMAIN` di Laravel atau strip atribut itu di proxy. Sanctum SPA
juga butuh `/sanctum/csrf-cookie` + header `X-XSRF-TOKEN`, yang belum ada di
`web/src/lib/api.js`.

## Produksi satu domain

`Dockerfile` + `package.json` root membangun `web/dist` lalu menjalankan satu proses
Node (`api/src/server.js`) yang menyajikan React di `/` (fallback SPA) dan API di
`/api/v1`. Di dev hal ini tidak aktif karena `web/dist` tidak ada di dalam mount
`api/` — Vite tetap yang melayani port 3000. Deploy: lihat
`docs/DEPLOY-SATU-DOMAIN.md` dan `.env.production.example` (`DATABASE_URL`,
`COOKIE_SECURE=1`). Seed demo idempotent, aman dijalankan setiap boot.

## Verifikasi

```bash
docker compose -f docker-compose.base44.yml ps
curl -s -o /dev/null -w "%{http_code}\n" localhost:3000/api/v1/me        # 401 tanpa sesi
curl -s -c /tmp/c -X POST localhost:3000/api/v1/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@sesmartpulsa.id","password":"demo12345"}'
curl -s -b /tmp/c localhost:3000/api/v1/products | head -c 200
```

Reset data demo:

```bash
docker compose -f docker-compose.base44.yml exec -T db psql -U pulsa -d pulsa -c "
DELETE FROM balance_entries; DELETE FROM transactions; DELETE FROM deposits;
DELETE FROM login_attempts; DELETE FROM sessions; DELETE FROM audit_logs;
UPDATE users SET balance = 0;"
docker compose -f docker-compose.base44.yml run --rm migrate   # seed ulang riwayat demo
```

## Catatan

- Produk disimpan dengan `is_demo = true` dan selalu ditampilkan dengan label DEMO.
  Tidak ada provider PPOB nyata; jangan mengarang harga real-time atau status provider.
- Schema lama (`accounts`) dari build pertama di-drop otomatis oleh `setup-db.js`.
- Halaman: `/login`, `/` (beranda), `/transaksi`, `/saldo`, `/profil`, `/beli/:id`,
  `/bantuan`, `/legal/:doc`. Semua selain `/login` butuh sesi.
