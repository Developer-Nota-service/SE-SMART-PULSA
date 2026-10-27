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

- `POST /register` `{name,email,phone,password,accountType}` → 201 **tanpa sesi**:
  menyimpan akun yang belum terverifikasi, mengirim kode OTP WhatsApp, dan
  mengembalikan `{ email, verification }` (`phoneMasked`, `expiresInSeconds`,
  `resendAfterSeconds`, `status`: `sent` | `failed` | `cooldown`).
  `accountType` wajib `reseller` | `personal` (default `reseller`) dan menentukan
  harga produk akun tersebut. Bisa diubah kapan saja lewat
  `PATCH /profile/account-type` (tanpa mendaftar ulang).
  Mendaftar ulang dengan email yang belum terverifikasi memperbarui data dan
  mengirim kode baru (bukan membuat akun kedua).
- `POST /verify-otp` `{email,code}` → 200 (+ `token`, cookie `sesp_session`) setelah
  kode benar; sekaligus menandai `email_verified_at` + `phone_verified_at`.
- `POST /resend-otp` `{email}` → 200 `{ verification }` (jeda 60 detik antar kode).
- `POST /login` `{email,password}` → 200, set cookie `sesp_session` (httpOnly, SameSite=Lax) + `token` di body.
  Akun yang belum terverifikasi dijawab **403** `{ data: { needsVerification: true,
  email, verification } }` dan kode OTP baru dikirim.
- `GET /me` → `{ user, lastLoginAt }` (`user.emailVerified`, `user.phoneVerified`,
  `user.avatarUrl`) · `POST /logout`
- `PATCH /profile` `{name}` — ubah nama tampilan (2-60 karakter).
- `POST /profile/contact` `{email,phone}` → 200 `{ verification }`: kode OTP dikirim ke
  nomor WhatsApp **baru**. Email/nomor baru baru diterapkan (dan keduanya ditandai
  terverifikasi) setelah `POST /profile/contact/verify` `{code}` benar. Nomor lama
  tetap aktif sampai kode dikonfirmasi.
- `PATCH /profile/account-type` `{accountType}` — pindah reseller ↔ pribadi tanpa
  daftar ulang; harga, metode bayar, dan fitur langsung mengikuti tipe baru. Pindah
  ke pribadi ditolak 422 bila saldo > 0 atau masih ada deposit pending, supaya saldo
  tidak tertahan.
- `POST /profile/avatar` (multipart `avatar`, JPG/PNG/WEBP maks 2 MB) → `user.avatarUrl`;
  file disimpan di `storage/app/private/avatars` dan disajikan lewat
  `GET /avatar/{file}` (nama acak 32 hex, tanpa sesi karena dipakai `<img>`).
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
- `POST /midtrans/sync` `{orderNumber?}` — cek status resmi ke API Midtrans untuk
  pesanan pending milik pengguna (cadangan bila webhook tidak sampai), signature
  respons diverifikasi sebelum status diubah

Akun demo: `demo@sesmartpulsa.id` / `demo12345`. Admin: `admin@sesmartpulsa.id` /
`admin12345`. Data demo: saldo Rp186.900, 4 transaksi (success/pending/failed),
4 deposit (paid/pending/expired), 95 produk DEMO di 7 kategori.

## Verifikasi akun (OTP WhatsApp via Fonnte)

Akun baru tidak bisa dipakai sebelum nomor WhatsApp-nya terbukti aktif. Alurnya:
`POST /register` → kode 6 angka dikirim ke WhatsApp nomor pendaftar lewat Fonnte →
`POST /verify-otp` → barulah sesi dibuat. Akun lama yang belum terverifikasi
(`email_verified_at` NULL) dipaksa ke langkah yang sama saat `POST /login`.

- `app/Services/OtpService.php` — terbitkan & periksa kode: berlaku 5 menit, jeda
  kirim ulang 60 detik, maksimal 5 percobaan salah per kode, kode lama otomatis
  dibatalkan saat kode baru terbit. Hanya `code_hash` (SHA-256) yang disimpan —
  jangan pernah menyimpan atau mengembalikan kode asli. Dua `purpose`:
  `account_verification` (verifikasi akun baru) dan `contact_change` (perubahan
  email/WhatsApp dari halaman profil; perubahan disimpan di kolom `payload` dan
  diterapkan saat kode benar).
- `app/Services/FonnteClient.php` — `POST https://api.fonnte.com/send` dengan header
  `Authorization: <FONNTE_TOKEN>`, form `target` (format `62...`) + `message`.
- `config/fonnte.php` → `FONNTE_TOKEN`, `FONNTE_API_URL`. Token berasal dari
  dashboard Fonnte (Device > Token) dan dikirim platform lewat
  `/run/base44/app.env`; **jangan** menaruhnya di compose `environment:`.
- Satu nomor terverifikasi hanya boleh dipakai satu akun (registrasi kedua ditolak
  422 `phone`).
- Sandbox: bila pengiriman gagal **dan** `BASE44_PREVIEW_MODE=1`, respons berisi
  `verification.devCode` supaya alur bisa dicoba tanpa WhatsApp. `devCode` selalu
  `null` di luar preview mode atau saat Fonnte berhasil mengirim.
- Akun demo/admin di-seed dengan `email_verified_at` + `phone_verified_at` terisi
  agar login demo tetap tanpa OTP.

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
URL) ke `https://<domain>/api/v1/midtrans/notification`. Di sandbox Base44 URL-nya
`https://3000-$BASE44_PUBLIC_HOST_SUFFIX/api/v1/midtrans/notification` dan **berubah
setiap sandbox dibuat ulang** — kalau notifikasi berhenti masuk setelah sandbox
baru, perbarui URL di dashboard Midtrans.

**Cadangan bila notifikasi tidak sampai:** `POST /api/v1/midtrans/sync`
`{orderNumber?}` (butuh sesi). Server memanggil
`GET /v2/{order_id}/status` Midtrans dengan Server Key, memverifikasi
`signature_key` pada respons (aturan yang sama seperti webhook), lalu memakai
`App\Services\MidtransPaymentStatus` — kelas yang sama dengan webhook — untuk
mengubah status. Tanpa `orderNumber`, maksimal 5 deposit pending dan 5 transaksi
pending milik pengguna diperiksa. Frontend memanggilnya lewat
`web/src/lib/usePaymentSync.js` setiap halaman Transaksi/Saldo dibuka (termasuk
`/transaksi?order_id=...` dari redirect Midtrans), lalu memuat ulang data bila ada
yang berubah.

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
- Hanya dua jalur yang boleh menandai deposit `paid` (dan menambah saldo):
  webhook Midtrans yang `signature_key`-nya valid, dan `POST /midtrans/sync` yang
  membaca status resmi dari API Midtrans lalu memverifikasi `signature_key` pada
  responsnya. Jangan tambahkan tombol "tandai lunas" atau mempercayai status dari
  browser.
- Setiap perubahan saldo menulis `balance_entries` dalam transaksi DB yang sama.
- Password bcrypt (`Hash::make`), login dibatasi 5 percobaan gagal / 15 menit per email.
- Sesi hanya terbit setelah OTP WhatsApp terverifikasi (registrasi maupun
  `/verify-otp`). Jangan menambah jalur yang membuat sesi untuk akun dengan
  `phone_verified_at` NULL, dan jangan pernah mengirim kode OTP di luar
  `verification.devCode` yang dibatasi preview mode.
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

# Alur OTP: daftar (kode dikirim ke WhatsApp) lalu verifikasi dengan kode dari WA
curl -s -X POST localhost:3000/api/v1/register -H 'Content-Type: application/json' \
  -d '{"name":"Uji OTP","email":"uji@example.com","phone":"08xxxxxxxxxx","password":"rahasia123","accountType":"personal"}'
curl -s -X POST localhost:3000/api/v1/verify-otp -H 'Content-Type: application/json' \
  -d '{"email":"uji@example.com","code":"123456"}'
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
