# AGENTS.md — SE SMART PULSA

PPOB demo app. **Backend Laravel 13 (PHP 8.4) + Postgres**, frontend React (Vite)
di `web/`. Satu domain: Laravel melayani API `/api/v1` dan React hasil build di `/app`.

## Menjalankan (sandbox/base44)

`docker-compose.base44.yml` menjalankan semuanya dari source:

- `web` — Vite dev server, host port **3000** → 5173 (entry preview).
- `laravel` — `php artisan serve` di 8000; otomatis `migrate` + `db:seed` saat start.
- `db` — Postgres 16, volume `pgdata`. Data backend Node lama tetap ada di database
  `pulsa`; Laravel memakai database sendiri `pulsa_laravel` (dibuat `dbsetup`).
- `dbsetup` — one-shot: `CREATE DATABASE pulsa_laravel` bila belum ada.

Browser hanya bicara ke port 3000; Vite mem-proxy `/api` → `http://laravel:8000`
(single origin, cookie sesi terkirim).

**Jangan membuat file `.env` di root container Laravel.** Compose sudah mengirim
environment (`APP_KEY`, `DB_*`, `SESSION_DRIVER`); `php artisan serve` mewariskan env
terbatas ke built-in server sehingga `.env` akan menang dan memutus koneksi DB.
`.env` di repo di-*ignore* git, jangan dibuat ulang.

Setelah mengubah `composer.json`/`package.json`, restart service terkait:

```bash
docker compose -f docker-compose.base44.yml restart laravel   # composer
docker compose -f docker-compose.base44.yml restart web       # npm
```

## Struktur

- `routes/api.php` + `app/Http/Controllers/Api/*` — kontrak API v1.
- `app/Http/Middleware/AuthenticateSessionToken.php` — token cookie `sesp_session`
  atau `Authorization: Bearer`.
- `database/migrations/*` — skema; `database/seeders/DatabaseSeeder.php` — produk +
  akun + riwayat demo (idempotent, aman dijalankan setiap boot).
- `web/` — React; `vite.config.js` build ke `public/app` dengan base `/app/`.
- `routes/web.php` — `/` → `/app`; `/app/{any}` → `public/app/index.html` (SPA fallback).

## Kontrak API (v1)

Semua di bawah `/api/v1`. Respons sukses `{ "data": ... }`; error
`{ "message": "...", "errors": { field: [ ... ] } }`.

- `POST /register` `{name,email,phone,password}` → 201 (+ `token`)
- `POST /login` `{email,password}` → 200, set cookie `sesp_session` (httpOnly, SameSite=Lax) + `token` di body
- `GET /me` → `{ user, lastLoginAt }` · `POST /logout`
- `GET /products?category=` · `GET /products/:id`
- `GET /transactions?status=&category=&from=&to=` · `GET /transactions/:id`
- `POST /transactions` `{productId,customerNumber}` + header opsional `Idempotency-Key`
- `GET /balance/entries` (ledger) · `GET /deposits` · `POST /deposits` `{amount}`

Akun demo: `demo@sesmartpulsa.id` / `demo12345`. Admin: `admin@sesmartpulsa.id` /
`admin12345`. Data demo: saldo Rp186.900, 4 transaksi (success/pending/failed),
4 deposit (paid/pending/expired), 95 produk DEMO di 7 kategori.

## Invariant keamanan (jangan dilanggar)

- Harga dan saldo ditentukan server. Charge berjalan atomik di SQL
  (`UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?`) — jangan
  bandingkan BIGINT di PHP float.
- `POST /deposits` hanya membuat status `pending`. **Tidak ada** endpoint yang bisa
  menandai deposit `paid`; itu tugas notifikasi Midtrans yang diverifikasi. Jangan
  tambahkan tombol "tandai lunas".
- Setiap perubahan saldo menulis `balance_entries` dalam transaksi DB yang sama.
- Password bcrypt (`Hash::make`), login dibatasi 5 percobaan gagal / 15 menit per email.
- `COOKIE_SECURE=1` untuk deployment HTTPS. Cookie `sesp_session` dikecualikan dari
  `EncryptCookies` (token acak, bukan payload terenkripsi).

## Deploy

Lihat `docs/DEPLOY-SATU-DOMAIN.md`. Ringkas: Dokploy framework **Laravel**
(root repo, port **80**), `npm run build` untuk React, dan environment
`APP_KEY`, `DATABASE_URL`, `COOKIE_SECURE=1`. `Dockerfile` tersedia bila memakai
build type Dockerfile.

## Verifikasi

```bash
docker compose -f docker-compose.base44.yml ps
curl -s -o /dev/null -w "%{http_code}\n" localhost:3000/api/v1/me        # 401 tanpa sesi
curl -s -c /tmp/c -X POST localhost:3000/api/v1/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@sesmartpulsa.id","password":"demo12345"}'
curl -s -b /tmp/c localhost:3000/api/v1/products | head -c 200
```

Build produksi React + cek halaman yang dilayani Laravel:

```bash
npm run build                       # → public/app
docker compose -f docker-compose.base44.yml exec -T web \
  node -e "fetch('http://laravel:8000/app').then(r=>console.log(r.status))"
```

Catatan: `php artisan serve` (dev) hanya melayani `/app` dan `/app/`;
sub-rute SPA seperti `/app/transaksi` di dev ditangani Vite di port 3000,
sedangkan di produksi (Apache + `public/.htaccess`) semuanya jatuh ke Laravel.

## Catatan

- Produk disimpan dengan `is_demo = true` dan selalu ditampilkan dengan label DEMO.
  Tidak ada provider PPOB nyata; jangan mengarang harga real-time.
- Halaman: `/login`, `/` (beranda), `/transaksi`, `/saldo`, `/profil`, `/beli/:id`,
  `/bantuan`, `/legal/:doc`. Semua selain `/login` butuh sesi.
