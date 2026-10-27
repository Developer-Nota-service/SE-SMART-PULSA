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

- `POST /register` `{name,email,phone,password,accountType}` → 201 (+ `token`);
  `accountType` wajib `reseller` | `personal` (default `reseller`) dan menentukan
  harga produk akun tersebut. Tidak ada endpoint untuk mengubahnya setelah daftar.
- `POST /login` `{email,password}` → 200, set cookie `sesp_session` (httpOnly, SameSite=Lax) + `token` di body
- `GET /me` → `{ user, lastLoginAt }` · `POST /logout`
- `GET /products?category=` · `GET /products/:id` — `price` adalah harga untuk tipe
  akun peminta, `retailPrice` harga publik
- `GET /transactions?status=&category=&from=&to=` · `GET /transactions/:id`
- `POST /transactions` `{productId,customerNumber}` + header opsional `Idempotency-Key`
  — **reseller saja**: bayar pakai saldo, harga reseller (akun pribadi ditolak 422)
- `POST /transactions/direct` `{productId,customerNumber}` → transaksi `pending` +
  `{transaction, payment:{token,redirectUrl}}` — **akun pribadi saja**: bayar langsung
  Midtrans, harga publik (reseller ditolak 422, wajib pakai saldo)
- `GET /balance/entries` (ledger) · `GET /deposits` · `POST /deposits` `{amount}`
  (`POST /deposits` mengembalikan `payment`/`paymentError` untuk Snap; hanya akun
  reseller — akun pribadi ditolak 422 karena tidak memakai saldo)
- `POST /midtrans/notification` — webhook publik, diverifikasi lewat `signature_key`

Akun demo: `demo@sesmartpulsa.id` / `demo12345`. Admin: `admin@sesmartpulsa.id` /
`admin12345`. Data demo: saldo Rp186.900, 4 transaksi (success/pending/failed),
4 deposit (paid/pending/expired), 95 produk DEMO di 7 kategori.

## Pembayaran Midtrans (Snap + webhook)

Harga dan metode bayar ditentukan oleh tipe akun (`users.account_type`):

- **Reseller** — `products.price` (lebih murah), **wajib isi saldo** dan membayar
  dengan saldo (`POST /transactions`). Jalur Midtrans langsung ditolak.
- **Pribadi** — `products.retail_price` (5% di atas harga saldo, dibulatkan ke atas
  per Rp100), **tidak memakai saldo**: semua transaksi lewat Midtrans
  (`POST /transactions/direct`) dan `POST /deposits` ditolak.

`app/Support/Pricing::unitPrice()` adalah satu-satunya sumber aturan ini dan dipakai
oleh `CatalogController` (harga yang dikirim ke UI), `POST /transactions` (potong
saldo) dan `POST /transactions/direct` (tagihan Midtrans; status `pending` sampai
webhook melunasi, saldo tidak dipotong). Akun lama dan akun demo tetap `reseller`.

Webhook: `POST /api/v1/midtrans/notification`. Server memverifikasi
`sha512(order_id + status_code + gross_amount + MIDTRANS_SERVER_KEY)`, mencocokkan
nominal dengan pesanan, lalu:

- deposit → `paid` + saldo bertambah + satu baris `balance_entries` (credit);
- transaksi langsung → `success` (`paid_at` diisi). Idempoten: notifikasi berulang
  diabaikan karena status terminal tidak diproses lagi.

Daftarkan URL notifikasi di Dashboard Midtrans (Settings > Payment > Notification
URL) ke `https://<domain>/api/v1/midtrans/notification`.

Env (`config/services.php → midtrans`): `MIDTRANS_SERVER_KEY`,
`MIDTRANS_CLIENT_KEY`, `MIDTRANS_IS_PRODUCTION`, `MIDTRANS_FINISH_URL`. Di sandbox
nilainya berasal dari `.env.base44-defaults` (placeholder) lalu
`/run/base44/app.env` (nilai asli menang). Placeholder sengaja ditolak saat
`APP_ENV=production`, jadi produksi wajib mengisi Server Key asli.

## Invariant keamanan (jangan dilanggar)

- Harga dan saldo ditentukan server. Charge berjalan atomik di SQL
  (`UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?`) — jangan
  bandingkan BIGINT di PHP float.
- Harga selalu `Pricing::unitPrice(price, retail_price, account_type)`. Jangan
  pernah menerima nominal dari browser, dan jangan mengambil tipe akun dari body
  request — hanya dari pengguna yang terautentikasi.
- Satu-satunya jalur yang boleh menandai deposit `paid` (dan menambah saldo) adalah
  webhook Midtrans yang signature-nya valid. Jangan tambahkan tombol "tandai lunas".
- Setiap perubahan saldo menulis `balance_entries` dalam transaksi DB yang sama.
- Password bcrypt (`Hash::make`), login dibatasi 5 percobaan gagal / 15 menit per email.
- `COOKIE_SECURE=1` untuk deployment HTTPS. Cookie `sesp_session` dikecualikan dari
  `EncryptCookies` (token acak, bukan payload terenkripsi).

## Deploy

Lihat `docs/DEPLOY-SATU-DOMAIN.md`. Ringkas: DockHosting project `se-smart-pulsa`
framework **Laravel** (root repo, port **80**), `npm run build` untuk React, dan
environment `APP_KEY`, `DATABASE_URL` (Postgres `se-smart-pulsa-db`),
`COOKIE_SECURE=1`. Build type Dockerfile memakai `Dockerfile` + `docker/entrypoint.sh`,
yang otomatis `migrate --force` + `db:seed` ke database kosong saat container start.
Auto-deploy hosting mengikuti branch `main`; pastikan branch itu sudah berisi kode
Laravel, bukan backend Node lama.

## Verifikasi

```bash
docker compose -f docker-compose.base44.yml ps
curl -s -o /dev/null -w "%{http_code}\n" localhost:3000/api/v1/me        # 401 tanpa sesi
curl -s -c /tmp/c -X POST localhost:3000/api/v1/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@sesmartpulsa.id","password":"demo12345"}'
curl -s -b /tmp/c localhost:3000/api/v1/products | head -c 200
```

Uji webhook Midtrans dengan key placeholder sandbox (signature dihitung dari
`MIDTRANS_SERVER_KEY` yang sama):

```bash
ORDER=DEP-20261027-0001; AMOUNT=50000.00; KEY=SB-Mid-server-BASE44DEVPLACEHOLDER
SIG=$(printf '%s' "${ORDER}200${AMOUNT}${KEY}" | sha512sum | cut -d' ' -f1)
curl -s -X POST localhost:3000/api/v1/midtrans/notification \
  -H 'Content-Type: application/json' \
  -d "{\"order_id\":\"$ORDER\",\"status_code\":\"200\",\"gross_amount\":\"$AMOUNT\",\"transaction_status\":\"settlement\",\"signature_key\":\"$SIG\"}"
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
