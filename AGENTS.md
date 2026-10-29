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
  mengembalikan `{ email, verification }` (`channel`, `targetMasked`,
  `expiresInSeconds`, `resendAfterSeconds`, `status`: `sent` | `failed` | `cooldown`).
  `accountType` wajib `reseller` | `personal` (default `reseller`) dan menentukan
  harga produk akun tersebut. Bisa diubah kapan saja lewat
  `PATCH /profile/account-type` (tanpa mendaftar ulang).
  Mendaftar ulang dengan email yang belum terverifikasi memperbarui data dan
  mengirim kode baru (bukan membuat akun kedua).
- `POST /verify-otp` `{email,code}` → 200 (+ `token`, cookie `sesp_session`) setelah
  kode benar; sekaligus menandai `email_verified_at` + `phone_verified_at`.
- `POST /resend-otp` `{email}` → 200 `{ verification }` (jeda 60 detik antar kode).
- `POST /password/forgot` `{phone}` → 200 `{ verification }`: kirim kode OTP WhatsApp
  (`purpose: password_reset`) ke nomor terdaftar; nomor tak terdaftar dijawab pesan
  generik dengan `verification: null` (anti-enumerasi).
- `POST /password/verify` `{phone,code}` → 200 `{ email, resetToken, expiresInSeconds }`:
  kode benar menampilkan email akun (untuk pengguna yang lupa email) dan membuka
  sesi reset 15 menit (hash token di tabel `password_reset_tokens`).
- `POST /password/reset` `{resetToken,password}` → 200 (+ sesi masuk): sandi baru
  disimpan, sesi lama akun diputus, token reset dihapus.
- `POST /login` `{email,password}` → 200, set cookie `sesp_session` (httpOnly, SameSite=Lax) + `token` di body.
  Akun yang belum terverifikasi dijawab **403** `{ data: { needsVerification: true,
  email, verification } }` dan kode OTP baru dikirim.
- `GET /me` → `{ user, lastLoginAt, lastLogin }` (`user.emailVerified`,
  `user.phoneVerified`, `user.avatarUrl`) · `POST /logout`
  `lastLogin` = `{ at, ip, location, device: { label, type, os, browser } }` dari
  login terakhir (`app/Support/LoginContext.php`). Perangkat diurai dari
  User-Agent; lokasi diperkirakan dari IP publik lewat ipwho.is (null bila gagal,
  `"Jaringan lokal"` untuk IP privat). IP klien diambil dari `X-Forwarded-For` /
  `X-Real-IP` dulu — proxy Vite memakai `xfwd: true` — jadi pastikan proxy
  produksi ikut mengirim header itu. Data ini hanya informasi untuk pengguna,
  bukan dasar keputusan keamanan.
- `GET /notifications` → `{ items, unreadCount }` — pemberitahuan akun untuk lonceng
  di header: transaksi yang gagal (alasan supplier + status pengembalian dana) dan
  status penarikan saldo. Tidak ada tabel notifikasi; isinya diturunkan
  `app/Http/Controllers/Api/NotificationController.php` dari `transactions`
  (`status = failed`) dan `withdrawals`, diurutkan berdasarkan waktu kejadian
  (`refunded_at` → `fulfilled_at` → `created_at`, atau `processed_at` →
  `created_at`). Belum dibaca = waktu kejadian lebih baru dari
  `users.notifications_read_at`.
  `POST /notifications/read` — tandai semua sudah dibaca (titik merah hilang).
- `PATCH /profile` `{name}` — ubah nama tampilan (2-60 karakter).
- `POST /profile/contact` `{email,phone}` → 200 `{ pending }`: setiap kontak yang
  berubah mendapat kode sendiri — email baru lewat **email** (`channel: email`),
  nomor baru lewat **WhatsApp** (`channel: whatsapp`). `pending` berisi kunci
  `email` dan/atau `phone`, tiap nilai `{channel, targetMasked, expiresInSeconds,
  resendAfterSeconds, status, devCode}`. Perubahan baru diterapkan setelah
  `POST /profile/contact/verify` `{channel, code}` benar; kolom yang bersangkutan
  lalu ditandai terverifikasi. Kontak lama tetap aktif sampai kode dikonfirmasi.
- `PATCH /profile/account-type` `{accountType}` — pindah reseller ↔ pribadi tanpa
  daftar ulang; harga, metode bayar, dan fitur langsung mengikuti tipe baru. Pindah
  ke pribadi ditolak 422 bila masih ada deposit pending atau penarikan yang
  diproses; saldo boleh tetap ada karena akun pribadi juga bisa menariknya.
- `POST /profile/avatar` (multipart `avatar`, JPG/PNG/WEBP maks 2 MB) → `user.avatarUrl`;
  file disimpan di `storage/app/private/avatars` dan disajikan lewat
  `GET /avatar/{file}` (nama acak 32 hex, tanpa sesi karena dipakai `<img>`).
- `GET /products?category=` · `GET /products/:id` — `price` adalah harga untuk tipe
  akun peminta, `retailPrice` harga publik
- `GET /transactions?status=&category=&from=&to=` · `GET /transactions/:id`
- `POST /transactions` `{productId,customerNumber,voucherNumber?}` + header opsional
  `Idempotency-Key` — **reseller saja**: bayar pakai saldo, harga reseller (akun
  pribadi ditolak 422). Untuk produk `voucher_inject`, `customerNumber` **adalah
  nomor voucher kosong** (8-20 digit), bukan email — `customer_no` inilah yang
  dikirim ke Digiflazz. `voucherNumber` masih diterima (opsional) dan diisi dari
  `customerNumber` bila kosong.
  Produk supplier (`is_demo = false`) dibuat `pending` lalu diselesaikan Digiflazz
  (lihat "Pemenuhan transaksi (Digiflazz)"); produk demo langsung `success`
- `POST /transactions/direct` `{productId,customerNumber,voucherNumber?}` → transaksi `pending` +
  `{transaction, payment:{token,redirectUrl}}` — **akun pribadi saja**: bayar langsung
  Midtrans, harga publik (reseller ditolak 422, wajib pakai saldo)
- `GET /balance/entries` (ledger) · `GET /deposits` · `POST /deposits` `{amount}`
  (`POST /deposits` mengembalikan `payment`/`paymentError` untuk Snap; hanya akun
  reseller — akun pribadi ditolak 422, saldonya hanya berasal dari pengembalian dana)
- `GET /admin/overview` — angka ringkas + antrean masalah. `GET /admin/withdrawals?status=`,
  `POST /admin/withdrawals/{id}` `{action: process|paid|reject, note?}` (tolak =
  saldo kembali), `GET /admin/deposits?status=`, `POST /admin/deposits/{id}/sync`,
  `POST /admin/deposits/{id}/cancel`, `GET /admin/transactions?status=&q=`,
  `POST /admin/transactions/{id}/check`, `POST /admin/transactions/{id}/refund`
  (tandai refund manual sudah dikirim),
  `GET /admin/users?q=`, `GET /admin/products?category=&q=&provider=`
  (`{data: {items, providers}}`; tiap baris memuat `costPrice`/`sellerName`/
  `maxCostPrice`/`costSyncedAt`, `providers` = ringkasan seller Digiflazz
  `{name, products}` yang ikut filter kategori/kata kunci),
  `PATCH /admin/products/{id}` `{price?,retailPrice?,maxCostPrice?,active?}` (harga
  publik tidak boleh di bawah harga reseller; `maxCostPrice` = batas harga modal,
  `0`/`null` = tanpa batas),
  `POST /admin/products/bulk-price` `{category,operator?,mode:percent|amount,value}`
  (menggeser harga reseller + publik sekaligus, `{updated}` = jumlah produk),
  `POST /admin/products/sync-digiflazz` → `{products,providers,active,skipped,deactivated}`:
  tarik harga modal terbaru dari Digiflazz (harga jual admin tidak ditimpa). 422
  bila kredensial kosong, 502 bila Digiflazz menolak (mis. limit pricelist).
  Konten beranda pengguna: `GET /admin/dashboard` (banner, promo, pengaturan,
  pratinjau peringkat), `POST /admin/banners`, `PATCH|DELETE /admin/banners/{id}`,
  `POST /admin/promos`, `PATCH|DELETE /admin/promos/{id}`,
  `PATCH /admin/dashboard-settings`
  `{leaderboardEnabled?,leaderboardLimit?,leaderboardPeriodDays?,leaderboardAccountType?}`
  (`leaderboardAccountType`: `all|reseller|personal`).
  Semua butuh `role = admin` (middleware `admin.only`).
- `GET /admin/overview` juga mengirim `salesDaily` (7 hari terakhir: `date`,
  `orders`, `revenue` transaksi sukses) dan `counts.salesToday` /
  `salesWeek` beserta jumlah pesanannya. Hari dihitung pada zona
  `Asia/Makassar` (`AdminController::REPORT_TIMEZONE`), bukan UTC.
- `GET /dashboard` (sesi pengguna) — banner aktif, promo aktif (yang belum
  kedaluwarsa), dan peringkat pembelian. Lihat "Konten beranda pengguna".
- `POST /midtrans/notification` — webhook publik, diverifikasi lewat `signature_key`
- `POST /midtrans/sync` `{orderNumber?}` — cek status resmi ke API Midtrans untuk
  pesanan pending milik pengguna (cadangan bila webhook tidak sampai), signature
  respons diverifikasi sebelum status diubah

Akun demo: `demo@sesmartpulsa.id` / `demo12345`. Data demo: saldo Rp186.900,
4 transaksi (success/pending/failed), 4 deposit (paid/pending/expired), 95 produk
DEMO di 7 kategori.

**Akun admin adalah akun pribadi pemilik** — `muhammadyusufrifaldi@gmail.com` /
`081346043318` (role `admin`, sudah terverifikasi). Tidak ada sandi default: akun
dibuat `DatabaseSeeder::seedOwnerAdmin()` tanpa sandi yang diketahui, lalu pemilik
mengatur sandinya lewat tautan email (lihat "Sandi admin" di bawah). Akun admin demo
lama `admin@sesmartpulsa.id` diturunkan menjadi `user` oleh seeder karena sandinya
tertulis di dokumentasi publik.

## Sandi admin (tautan email)

```bash
docker compose -f docker-compose.base44.yml exec -T laravel \
  php artisan admin:password-link muhammadyusufrifaldi@gmail.com --url=<alamat publik>
```

`app/Console/Commands/SendAdminPasswordLink.php` membuat token sekali pakai (hash di
`password_reset_tokens`, jalur yang sama dengan `POST /api/v1/password/reset`) lalu
mengirim email berisi `<url>/login?resetToken=...`. Halaman login mengenali parameter
itu dan menampilkan mode **Atur sandi** (`web/src/components/PasswordReset.jsx`), jadi
admin mengetik sandinya sendiri — tidak ada sandi yang dikirim lewat email.

Butuh SMTP nyata (`MAIL_MAILER=smtp` + kredensial dari dashboard). Selama
`MAIL_MAILER=log` (default sandbox), email hanya ditulis ke log dan perintah mencetak
tautannya ke console supaya alurnya tetap bisa dicoba.

## Verifikasi akun (OTP WhatsApp via Fonnte)

Akun baru tidak bisa dipakai sebelum nomor WhatsApp-nya terbukti aktif. Alurnya:
`POST /register` → kode 6 angka dikirim ke WhatsApp nomor pendaftar lewat Fonnte →
`POST /verify-otp` → barulah sesi dibuat. Akun lama yang belum terverifikasi
(`email_verified_at` NULL) dipaksa ke langkah yang sama saat `POST /login`.

- `app/Services/OtpService.php` — terbitkan & periksa kode: berlaku 5 menit, jeda
  kirim ulang 60 detik, maksimal 5 percobaan salah per kode, kode lama otomatis
  dibatalkan saat kode baru terbit. Hanya `code_hash` (SHA-256) yang disimpan —
  jangan pernah menyimpan atau mengembalikan kode asli. Empat `purpose`/kanal:
  `account_verification` (WhatsApp, verifikasi akun baru), `email_change` (email,
  kode dikirim ke **email baru**), `phone_change` (WhatsApp, kode dikirim ke
  **nomor baru**), dan `password_reset` (WhatsApp, lupa sandi/email lewat nomor
  terdaftar — kode benar juga menandai akun terverifikasi). Perubahan kontak disimpan di kolom `payload` dan baru diterapkan
  saat kode untuk kanal itu benar; kolom `otp_codes.target` menyimpan tujuan
  (nomor atau alamat email).
- Email OTP memakai `Mail` Laravel (config `mail`). Kanal dianggap siap hanya bila
  `MAIL_MAILER` bukan `log`/`array`; kalau belum diisi, perubahan email ditolak
  dengan pesan jelas (di preview mode kode dikembalikan sebagai `devCode`).
  Ada dua jalur pengiriman yang didukung, keduanya lewat dashboard (bukan compose
  `environment:`):
  - **SMTP** — `MAIL_MAILER=smtp` + `MAIL_HOST`/`MAIL_PORT`/`MAIL_SCHEME`/
    `MAIL_USERNAME`/`MAIL_PASSWORD`/`MAIL_FROM_ADDRESS`. Gmail menolak sandi akun
    (`535-5.7.8`); wajib App Password 16 karakter (2FA aktif).
  - **Kirisan Send API** — `MAIL_MAILER=kirisan` + `KIRISAN_API_TOKEN` (akun,
    `dash.kirisan.com > Account > Settings`) dan `KIRISAN_EMAIL_TOKEN` (sender,
    `Channel > Email > Emails`; butuh domain terverifikasi + berbayar).
    `app/Mail/Transport/KirisanTransport.php` memanggil
    `POST https://api.kirisan.com/v1/send` satu penerima per permintaan; `From`
    ditentukan token sender. Endpoint SMTP Kirisan (`smtp.kirisan.com`) belum ada
    di DNS, jadi jangan pakai jalur SMTP Kirisan.
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
  per Rp100), **transaksi tidak memakai saldo**: semua transaksi lewat Midtrans
  (`POST /transactions/direct`) dan `POST /deposits` ditolak. Saldo tetap ada untuk
  menampung pengembalian dana transaksi gagal dan bisa ditarik lewat menu Penarikan.

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

Refund transaksi gagal: `app/Services/TransactionRefund.php` mengkreditkan nominal
transaksi Midtrans yang gagal di supplier ke **saldo pengguna** (berlaku untuk
semua tipe akun, termasuk akun pribadi), tanpa memanggil API refund Midtrans.
Pengembaliannya selesai saat itu juga dan tidak bergantung pada metode pembayaran
(VA/transfer bank memang tidak mendukung refund Midtrans). Saldo itu bisa ditarik
lewat menu Penarikan.

Env (`config/services.php → midtrans`): `MIDTRANS_SERVER_KEY`,
`MIDTRANS_CLIENT_KEY`, `MIDTRANS_IS_PRODUCTION`, `MIDTRANS_FINISH_URL`. Di sandbox
nilainya berasal dari `.env.base44-defaults` (placeholder) lalu
`/run/base44/app.env` (nilai asli menang). Placeholder sengaja ditolak saat
`APP_ENV=production`, jadi produksi wajib mengisi Server Key asli.

## Konsol admin (`/admin`)

`app/Http/Controllers/Api/AdminController.php` + `web/src/pages/AdminPage.jsx`.
Dijaga `app/Http/Middleware/EnsureAdmin.php` (alias `admin.only`).

**Tata letak terpisah dari akun pengguna.** `/admin` tidak memakai `AppShell`
(beranda, saldo, dan navigasi belanja tidak tampil) — `AdminGate` di `App.jsx`
memeriksa `role`, dan `AdminPage.jsx` adalah shell sendiri (header navy, strip
identitas, nav 6 tab). Rutenya `/admin` (Ringkasan), `/admin/beranda`,
`/admin/harga`, `/admin/transaksi`, `/admin/keuangan?tab=deposit|penarikan`,
`/admin/pengguna`; tautan dari ringkasan membawa status di query
(`/admin/transaksi?status=failed`) sehingga antrean langsung terbuka. Admin
tetap bisa masuk ke aplikasi pengguna lewat tombol "Ke aplikasi".

- **Ringkasan** (`web/src/components/admin/AdminOverview.jsx`) — kartu angka
  penting plus **ringkasan penjualan harian** 7 hari (grafik batang + daftar
  pesanan/omzet). Omzet = transaksi `success`; deposit tidak dihitung sebagai
  penjualan.
- **Beranda** (`web/src/components/admin/AdminDashboard.jsx`) — banner dan promo
  beranda pengguna (tambah/ubah/tayangkan/hapus) serta pengaturan peringkat
  pembelian. Lihat "Konten beranda pengguna".

- **Harga** (`web/src/components/admin/AdminProducts.jsx`) — daftar produk per
  kategori, harga **modal** (`products.cost_price`, dari Digiflazz), **harga max**
  (`max_cost_price`), harga reseller (`products.price`), harga publik
  (`retail_price`), selisih %, provider (`seller_name`), dan status tayang.
  Pemilih **Provider Digiflazz** menyaring produk per seller dari daftar harga
  Digiflazz (ringkasan `{name, products}` dari `GET /admin/products`). Kartu
  "Harga modal Digiflazz" punya tombol **Ambil harga terbaru**
  (`POST /admin/products/sync-digiflazz`) yang memperbarui modal + provider seluruh
  seller sekaligus tanpa menyentuh harga jual yang sudah diatur admin. Sheet "Ubah
  harga" juga mengisi **Harga max modal** (`max_cost_price`) — batas harga modal
  yang mau dibayar; dikirim ke Digiflazz sebagai `max_price` sehingga transaksi
  ditolak (dan saldo pembeli dikembalikan) bila harga supplier menembus batas itu.
  Satu produk diubah
  lewat sheet "Ubah harga"; satu kategori bisa digeser massal (persen atau
  rupiah) di kartu "Ubah harga massal". Produk nonaktif (`active = false`)
  hilang dari katalog pengguna, jadi AdminController menolak harga publik di
  bawah harga reseller agar akun pribadi tidak membayar lebih murah.

- **Penarikan** — `pending → processing → paid`; `pending|processing → rejected`.
  Status final tidak bisa diubah (409), jadi saldo tidak bisa kembali dua kali.
  Tolak menambah saldo pengguna secara atomik + satu baris `balance_entries`
  (credit, "Pengembalian saldo: penarikan ditolak") dalam transaksi DB yang sama,
  lalu menulis `audit_logs` (`admin.withdrawal.*`) dengan id admin.
- **Deposit** — `sync` memakai `MidtransService::fetchStatus()` lalu **wajib**
  memverifikasi `signature_key` respons sebelum `MidtransPaymentStatus::apply()`
  (jalur yang sama dengan webhook). 404 dari Midtrans = halaman pembayaran belum
  pernah dibuka, dijawab 409 dan sarankan membatalkan. `cancel` hanya untuk
  `pending` (409 untuk status lain) dan tidak menyentuh saldo karena deposit
  pending belum pernah menambah saldo. Tidak ada tombol "tandai lunas" manual.
- **Deposit menggantung** — deposit `pending` lebih tua dari 30 menit ditandai
  `stale` (+ `ageMinutes`) supaya gampang ditindak.
- Akun admin: akun pribadi pemilik (`muhammadyusufrifaldi@gmail.com`, role `admin`).
  Tidak ada akun admin kedua; jangan menyeed ulang akun dengan sandi publik.

## Konten beranda pengguna

Banner, promo, dan peringkat pembelian diatur admin di tab **Beranda**. Satu
sumber data: `app/Support/DashboardContent.php`, dipakai `DashboardController`
(pengguna) dan `AdminDashboardController` (admin).

- Tabel `banners` (judul, keterangan, `image_path`, `tone` `navy|blue|green|amber`,
  `active`, `sort_order`) dan `promos` (judul, keterangan, kode, label,
  `valid_until`, `active`, `sort_order`). Hanya baris `active` yang dikirim ke
  beranda; promo juga harus belum lewat `valid_until`. Urutan `sort_order` lalu
  terbaru.
- Banner memakai **gambar** (JPG/PNG/WEBP maks 4 MB) yang diunggah admin:
  `POST /admin/banners` dan `POST /admin/banners/{id}` (multipart; PATCH tetap
  untuk ubahan JSON seperti tayang/sembunyi), field file `image`, `removeImage=1`
  untuk menghapus. File disimpan di `storage/app/private/banners` dengan nama acak
  32 hex dan disajikan publik lewat `GET /banner/{file}` (tanpa sesi, dipakai
  `<img>`). Judul opsional bila gambar ada — dipakai sebagai teks alternatif, dan
  `tone` hanya tampilan cadangan untuk banner lama tanpa gambar.
- Beranda menampilkan banner sebagai **slider otomatis** (`HomeBanners`): satu
  slide per layar, bergeser tiap 4 detik, dengan titik penunjuk yang bisa diklik.
- Peringkat **tidak punya tabel sendiri** — dihitung dari transaksi `success`
  milik `users.role = 'user'` pada rentang `leaderboardPeriodDays` (7/30/90),
  diurutkan dari jumlah transaksi lalu nominal, dengan pilihan peserta semua
  akun / reseller / pribadi. Akun admin tidak pernah masuk peringkat.
- Nama pembeli disamarkan server (`DashboardContent::maskName()` →
  `Bud*** S.`) karena peringkat tampil ke semua pengguna; jangan kirim nama
  lengkap.
- Pengaturan ada di `dashboard_settings` (key/value): `leaderboardEnabled`,
  `leaderboardLimit` (3/5/10), `leaderboardPeriodDays` (7/30/90),
  `leaderboardAccountType` (`all|reseller|personal`). Nilai bawaan diisi
  `DashboardContent::settings()`, jadi tabel boleh kosong.
- Beranda (`web/src/pages/HomePage.jsx`) memuat `GET /dashboard` bersama
  transaksi terbaru; tampilannya `HomeBanners`, `HomePromos`, dan
  `HomeLeaderboard` (menyembunyikan diri bila kosong atau peringkat nonaktif).

## Penarikan saldo (withdraw)

`GET /withdrawals/channels` · `GET /withdrawals` ·
`POST /withdrawals` `{amount,channelType,providerCode,accountNumber,accountHolder}`.

- Minimal Rp100.000, kelipatan Rp100.000, proses 1-7 hari kerja, berlaku untuk
  **semua tipe akun** — saldo akun pribadi berasal dari pengembalian dana.
- Saldo dipotong atomik bersama `balance_entries` saat pengajuan; penarikan yang
  masih diproses menahan perubahan tipe akun ke pribadi.
- **Nama pemilik rekening diisi pengguna, bukan hasil pengecekan otomatis.**
  Tidak ada penyedia cek nama rekening yang dipakai: Duitku, iPaymu, dan
  Digiflazz tidak menyediakan API-nya, sedangkan Xendit Data Services, DOKU
  Payout, Flip for Business, dan Brankas mensyaratkan akun badan usaha. Karena
  itu `store()` mewajibkan `accountHolder` (3-80 huruf) dan menyimpan
  `verification_status = unverified` + `verified_holder = NULL`. Pengelola wajib
  mencocokkan nama dan nomor rekening dengan data pengguna sebelum dana
  ditransfer. Jangan menandai `matched` tanpa sumber pengecekan yang nyata.
- Halaman penarikan menautkan `https://cekrekening.id/` di samping kolom nomor
  rekening. Itu portal Komdigi untuk memeriksa **laporan penipuan** sebuah nomor
  rekening — bukan penampil nama pemilik. Jangan menampilkannya sebagai hasil
  verifikasi nama.
- Bila kelak ada penyedia yang bisa dipakai (mis. Xendit Data Services setelah
  akun badan usaha disetujui), tambahkan sebagai service baru yang mengembalikan
  nama pemilik rekening dan pasang di `store()`; frontend tidak perlu diubah.
- Jalur pendaftaran **Midtrans Iris/Payouts** (validasi rekening lewat
  `GET /iris/api/v1/account_validation` + pencairan dana) dirinci di
  `docs/MIDTRANS-IRIS.md`. Belum diintegrasikan: menunggu akun Iris aktif dan
  secret `MIDTRANS_IRIS_API_KEY`.

## Pemenuhan transaksi (Digiflazz)

Transaksi produk nyata dikirim ke buyer API Digiflazz setelah dibayar.

- `config/digiflazz.php` → `DIGIFLAZZ_USERNAME`, `DIGIFLAZZ_API_KEY`,
  `DIGIFLAZZ_WEBHOOK_SECRET` (dashboard Secrets), `DIGIFLAZZ_TESTING` (default
  `true` = mode development Digiflazz; set `false` saat go-live),
  `DIGIFLAZZ_MARGIN_PERCENT` (default 5).
- `app/Services/DigiflazzClient.php` — `POST /v1/price-list` (sign
  `md5(username+apiKey+"pricelist")`) dan `POST /v1/transaction` (sign
  `md5(username+apiKey+ref_id)`). `ref_id` = `transactions.order_number`, jadi
  memanggil topup ulang dengan ref_id yang sama berarti **cek status** (perilaku
  resmi Digiflazz).
- `app/Services/DigiflazzProductSync.php` (dipakai `php artisan digiflazz:sync-products`
  dan `POST /admin/products/sync-digiflazz`) — impor produk `is_demo = false` untuk
  kategori Pulsa, Data, PLN token, E-Money, Games, Voucher. Pascabayar, masa aktif,
  TV, dan eSIM dilewati. Voucher Telkomsel (produk inject) masuk kategori
  `voucher_inject`, bukan `voucher` — lihat `categoryFor()`. Tiap produk menyimpan
  **harga modal** (`cost_price`), provider (`seller_name`), dan `cost_synced_at`.
  Harga reseller = harga supplier + margin, harga publik +5%, keduanya dibulatkan
  ke atas per Rp100 — **hanya untuk produk baru**: harga jual produk yang sudah ada
  dipertahankan supaya sinkron tidak menghapus harga yang diatur admin di konsol.
  SKU yang hilang dari daftar dinonaktifkan (tidak dihapus) agar riwayat transaksi
  tetap utuh.
- `products.max_cost_price` (diisi admin) dikirim sebagai `max_price` saat topup
  (`TransactionFulfillment`), jadi harga supplier yang melonjak melewati batas itu
  membuat transaksi ditolak dan dana pembeli dikembalikan lewat jalur refund biasa.
  **Harga max hanya diatur di aplikasi** — daftar harga Digiflazz tidak mengirim
  nilai "harga maksimal" yang diisi di dashboard mereka (field respons hanya
  `price` + `seller_name`), jadi jangan mencari field itu di API; `max_price` per
  transaksi inilah yang menegakkan batasnya.
- **Isi `customer_no` mengikuti produk Digiflazz** (satu kolom teks; formatnya
  ditentukan seller lewat `desc` daftar harga): pulsa/data/e-wallet = nomor HP;
  token PLN = ID pelanggan/nomor meter; game = ID game/User ID, sebagian produk
  meminta gabungan ID + Zone ("Mobile Legends: gabungan user_id dan zone_id")
  atau `UID|Server` (Genshin/Honkai); voucher = email penerima (kodenya dikirim
  aplikasi sendiri dari `sn`); voucher inject = nomor voucher kosong. Validasi per
  kategori ada di `destinationError()` — produk game menerima huruf dan pemisah
  (`|`, titik, `#`), bukan hanya angka. `desc` seller disimpan di
  `products.instruction` saat sync dan tampil sebagai "Petunjuk produk" di halaman
  beli.
- `allow_dot: true` dikirim otomatis bila `customer_no` berisi titik (aturan resmi
  Digiflazz supaya seller non-Jabber tidak menolak).
- Daftar harga dibatasi frekuensinya; balasan limit berupa `data: {rc, message}`,
  **bukan** daftar kosong. `DigiflazzClient` melempar pesan limit itu (sync gagal
  dengan jelas dan tidak menonaktifkan produk).
- `app/Services/TransactionFulfillment.php` — `pending` → `success` / `failed`
  dengan `supplier_status`, `supplier_sn`, `supplier_message`, `fulfilled_at`.
  Transaksi saldo yang gagal di supplier dikembalikan **sekali** lewat
  `balance_entries` (credit); `supplier_status` final membuat webhook/cek berulang
  tidak mengubah apa pun.
- **Transaksi yang dibayar lewat Midtrans** dan gagal di supplier dikembalikan
  `app/Services/TransactionRefund.php`: nominal transaksi **langsung dikreditkan ke
  saldo pengguna** lewat `balance_entries`, untuk **semua tipe akun** (reseller
  maupun pribadi). API refund Midtrans tidak dipanggil, jadi pengembalian selesai
  saat itu juga dan tidak bergantung pada metode pembayaran.
  `refund_status = failed` hanya dipakai bila kredit saldo itu sendiri gagal —
  admin melihatnya di `GET /admin/overview` (`counts.refundsManual`, kartu
  "Refund manual"), mengirim dana ke rekening pembeli, lalu menandainya lewat
  `POST /admin/transactions/{id}/refund` (`refund_status = manual`) supaya antrean
  kembali kosong. Hasilnya di `transactions.refund_status` — `processing` →
  `balance` (dikreditkan ke saldo pengguna) atau `failed`/`manual` (penanganan
  admin) — plus `refund_message`/`refunded_at`. Klaim `refund_status` membuat
  pengembalian hanya terjadi **sekali** (webhook/cek berulang aman). Admin bisa
  memicu ulang pengembalian lewat `POST /admin/transactions/{id}/check`.
- `POST /api/v1/digiflazz/webhook` — publik, wajib `X-Hub-Signature:
  sha1=<hmac>` dari `DIGIFLAZZ_WEBHOOK_SECRET`; menjawab 503 bila secret belum
  diisi. Event `ping` dijawab `pong`, ref_id tak dikenal dijawab 200 agar
  Digiflazz tidak mengulang terus.
- `POST /api/v1/admin/transactions/{id}/check` — admin mengirim ulang/cek status ke
  supplier untuk transaksi `pending`.
- Respons transaksi memuat `serialNumber`, `supplierStatus`, `supplierMessage`,
  `refundStatus`, `refundMessage`, `refundedAt`; detail pesanan menampilkan nomor
  seri (token PLN/pulsa) beserta tombol salin, dan baris **Pengembalian dana**
  untuk transaksi yang gagal di supplier.

## Invariant keamanan (jangan dilanggar)

- Harga dan saldo ditentukan server. Charge berjalan atomik di SQL
  (`UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?`) — jangan
  bandingkan BIGINT di PHP float.
- Harga selalu `Pricing::unitPrice(price, retail_price, account_type)`. Jangan
  pernah menerima nominal dari browser, dan jangan mengambil tipe akun dari body
  request — hanya dari pengguna yang terautentikasi.
- Hanya tiga jalur yang boleh menandai deposit `paid` (dan menambah saldo), dan
  semuanya berdasar data Midtrans ber-signature: webhook
  `POST /midtrans/notification`, `POST /midtrans/sync` milik pengguna, dan
  `POST /admin/deposits/{id}/sync` (admin) — ketiganya memanggil
  `MidtransPaymentStatus::apply()` setelah `signature_key` terverifikasi. Jangan
  tambahkan tombol "tandai lunas" atau mempercayai status dari browser.
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

- Produk demo (`is_demo = true`, label DEMO) tidak pernah dikirim ke supplier.
  Produk hasil `digiflazz:sync-products` ber-`is_demo = false` dan benar-benar
  dikirim ke Digiflazz saat dibayar; harganya datang dari daftar harga supplier,
  jangan dikarang manual.
- Sebelum transaksi dikirim, halaman beli menampilkan `PurchaseConfirmSheet`
  (`web/src/components/PurchaseConfirmSheet.jsx`): produk, tujuan yang sudah
  diketik, total, dan `PURCHASE_DISCLAIMER` dari `web/src/lib/format.js` —
  kesalahan pengisian nomor/data lain bukan tanggung jawab pengelola dan transaksi
  yang sudah diproses tidak dapat dibatalkan. Permintaan baru dikirim setelah
  tombol "Ya, lanjutkan" ditekan; "Periksa lagi" menutup sheet tanpa transaksi.
  Keterangan pengembalian dana memakai `refundLabel()` di file yang sama.
- Tabel `products` menyimpan nama operator di kolom **`operator`**, bukan `brand`:
  field `brand` dari payload Digiflazz dipetakan ke `operator` oleh
  `SyncDigiflazzProducts`. Query inspeksi yang memakai `brand` gagal
  (`column "brand" does not exist`).
- Halaman: `/login`, `/` (beranda), `/kategori/:category`, `/transaksi`, `/saldo`,
  `/profil`, `/beli/:id`, `/bantuan`, `/legal/:doc`, `/admin`. Beranda hanya
  menampilkan kartu kategori (`web/src/components/CategoryGrid.jsx` → tautan);
  daftar produk muncul di `/kategori/:category` (`web/src/pages/CategoryPage.jsx`)
  tanpa kartu kategori lagi (hanya judul kategori):
  provider tampil sebagai deret chip menyamping (bisa di-scroll; urutan mengikuti
  `PROVIDER_ORDER` di `CategoryPage.jsx`: Telkomsel, Indosat/IM3, XL, Axis, by.U,
  Smartfren, lalu sisanya terbesar dulu; nama operator dipetakan lewat
  `PROVIDER_NAMES`),
  produk provider terpilih muncul menurun di bawahnya. Nama operator supplier yang
  beda huruf besar/kecil digabung (TELKOMSEL = Telkomsel).
  Kategori `voucher_inject` (Voucher Inject) berisi voucher Telkomsel yang harus
  di-inject ke voucher kosong; `CATEGORY_NOTES` di `CategoryPage.jsx` menampilkan
  penjelasan itu di atas chip provider. Berbeda dari voucher biasa (yang dikirim ke
  email penerima), voucher inject memakai **nomor voucher kosong** sebagai
  `customerNumber` — itu yang diteruskan ke Digiflazz sebagai `customer_no`
  (backend: `destinationError()` di `TransactionController` hanya menerima email
  untuk kategori `voucher`).
  Teks penjelasan + peringatan "salah ketik nomor bukan tanggung jawab kami" ada di
  `VOUCHER_INJECT_NOTE` (`web/src/lib/format.js`) dan dipakai halaman kategori
  maupun halaman beli. Khusus kategori ini halaman beli hanya punya **satu** kolom
  (tidak ada email): **Nomor voucher kosong yang akan di-inject**, wajib 8-20 digit
  angka. Nomor itu dikirim sebagai `customerNumber`, disalin ke
  `transactions.voucher_number` (`TransactionController`) dan ditampilkan di detail
  pesanan (`voucherNumber`). Kategori lain tidak memakai kolom ini.
  Semua selain `/login` butuh sesi; `/admin` dan sub-rutenya hanya untuk akun
  `role = admin` — akun lain dialihkan ke beranda, dan menu Admin tidak muncul di
  navigasi bawah.
