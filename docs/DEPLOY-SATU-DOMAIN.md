# Deploy satu domain (Laravel + React + PostgreSQL)

Repo ini adalah **aplikasi Laravel** di root. React ada di `web/` dan di-build ke
`public/app`, lalu disajikan Laravel di `https://domainmu.com/app`.

| URL | Isi |
| --- | --- |
| `/` | redirect ke `/app` |
| `/app`, `/app/transaksi`, `/app/saldo`, ... | React (SPA) |
| `/api/v1/...` | API Laravel (JSON) |
| `/up` | healthcheck Laravel |

## DockHosting (project `se-smart-pulsa`)

| Setelan | Nilai |
| --- | --- |
| Framework | **Laravel** |
| Repo / branch auto-deploy | `Developer-Nota-service/SE-SMART-PULSA` branch yang **berisi kode Laravel** (lihat catatan branch di bawah) |
| Direktori akar | kosong (`/`) |
| Port aplikasi | **80** (kosong = 80). Jangan 8000 — itu port `artisan serve` di sandbox. |
| Build command | `composer install --no-dev --optimize-autoloader && npm run build` |
| Start command | `php artisan migrate --seed --force && php-fpm` (atau default preset) |

`npm run build` di root hanya membangun React ke `public/app` (lihat `package.json`).
`--seed` idempotent, jadi aman dijalankan setiap deploy.

> **Catatan branch:** auto-deploy mengikuti branch `main`, yang sudah berisi kode
> Laravel (folder `api/` Node lama sudah tidak ada). Hosting hanya membawa kode yang
> sudah di-merge ke `main`, jadi merge branch fitur terbaru dulu sebelum deploy —
> kalau tidak, produksi menjalankan versi lama.

### Environment wajib

Salin ke tab **Environment** project (nilai database disalin dari service
PostgreSQL `se-smart-pulsa-db`, host **internal**, bukan `localhost`):

```env
APP_NAME="SE SMART PULSA"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domainmu.com
APP_KEY=                                    # php artisan key:generate --show
DATABASE_URL=postgres://postgres:PASSWORD@db-se-smart-pulsa-db-6ae04923:5432/se-smart-pulsa-db
DB_CONNECTION=pgsql
DB_HOST=db-se-smart-pulsa-db-6ae04923
DB_PORT=5432
DB_DATABASE=se-smart-pulsa-db
DB_USERNAME=postgres
DB_PASSWORD=PASSWORD                        # tombol "Rotate Password" di dashboard DB
SESSION_DRIVER=file                         # tabel `sessions` dipakai untuk token API
CACHE_STORE=file
QUEUE_CONNECTION=sync
COOKIE_SECURE=1
SESSION_SECURE_COOKIE=1
# IP proxy hosting (Traefik/Nginx) agar IP klien & rate limit benar.
# Pakai `*` hanya bila aplikasi tidak bisa diakses langsung tanpa proxy.
TRUSTED_PROXIES=*
MIDTRANS_SERVER_KEY=SB-Mid-server-xxxx      # Dashboard Midtrans > Settings > Access Keys
MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxx
MIDTRANS_IS_PRODUCTION=1
MIDTRANS_FINISH_URL=https://domainmu.com/app/transaksi

# WAJIB: tanpa token Fonnte, pendaftaran pengguna baru tidak pernah selesai
# (kode OTP WhatsApp tidak terkirim). Dashboard Fonnte > Device > Token.
FONNTE_TOKEN=
FONNTE_API_URL=https://api.fonnte.com/send

# Email: OTP ganti email + tautan "atur sandi" admin. MAIL_MAILER=log berarti
# email tidak benar-benar terkirim (hanya ke log).
MAIL_MAILER=smtp
MAIL_HOST=smtp.domainmu.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="******"

# Digiflazz (buyer API) > Atur Koneksi > API. Tanpa ini transaksi nyata berhenti
# di status pending; DIGIFLAZZ_TESTING=false saat memakai Production Key.
DIGIFLAZZ_USERNAME=
DIGIFLAZZ_API_KEY=
DIGIFLAZZ_WEBHOOK_SECRET=       # tab Webhook
DIGIFLAZZ_TESTING=false
DIGIFLAZZ_MARGIN_PERCENT=5
```

> **Akun demo tidak dibuat di produksi.** `DatabaseSeeder` melewati akun
> `demo@sesmartpulsa.id` dan riwayat demo saat `APP_ENV=production`, karena sandi
> akun itu tertulis di halaman login build dev dan di dokumentasi — sementara
> katalog berisi produk supplier asli. Halaman login build produksi
> (`npm run build`) juga tidak menampilkan kotak kredensial demo. Akun admin
> pemilik tetap dibuat, tanpa sandi default (lihat "Sandi admin").
>
> Bila database lama ikut dipakai di produksi, akun demo yang sudah ada tetap
> ada (seeder tidak menghapusnya). Netralkan sekali sebelum go-live:
> `UPDATE users SET password_hash = encode(gen_random_bytes(32), 'hex'), balance = 0 WHERE email = 'demo@sesmartpulsa.id';`

Alternatif: isi `DATABASE_URL` saja (format `postgres://USER:PASSWORD@HOST:5432/DB`).

### Midtrans (deposit & bayar langsung)

1. Isi `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY`, `MIDTRANS_IS_PRODUCTION=1`
   (pakai key `SB-Mid-...` + `0` untuk sandbox).
2. Di Dashboard Midtrans → **Settings → Payment → Notification URL**, isi:
   `https://domainmu.com/api/v1/midtrans/notification`
3. Pastikan `APP_URL` sudah domain HTTPS; `MIDTRANS_FINISH_URL` menentukan halaman
   balik setelah pembayaran.

Alur: deposit dibuat `pending` + tautan Snap; saldo bertambah hanya setelah webhook
`settlement`/`capture` dengan signature valid. Pembelian bayar langsung memakai
`retail_price` (harga publik) dan melunasi transaksi lewat webhook yang sama tanpa
memotong saldo. Tanpa Server Key asli, pembayaran menampilkan "Pembayaran Midtrans
belum dikonfigurasi".

### Digiflazz (pemenuhan transaksi)

1. Isi `DIGIFLAZZ_USERNAME`, `DIGIFLAZZ_API_KEY`, `DIGIFLAZZ_WEBHOOK_SECRET` dari
   Dashboard Digiflazz > **Atur Koneksi > API** (secret ada di tab **Webhook**).
   Set `DIGIFLAZZ_TESTING=false` untuk transaksi nyata.
2. Impor daftar produk: `php artisan digiflazz:sync-products`. Harga reseller =
   harga supplier + `DIGIFLAZZ_MARGIN_PERCENT` (default 5), harga publik +5%.
3. Daftarkan URL webhook di dashboard Digiflazz:
   `https://domainmu.com/api/v1/digiflazz/webhook`.
4. Transaksi `pending` yang webhook-nya tidak sampai bisa dikirim ulang dari
   konsol admin (`POST /api/v1/admin/transactions/{id}/check`).

Tanpa kredensial ini, produk demo tetap berjalan seperti biasa dan transaksi
produk nyata berhenti di status `pending` (tidak ada panggilan ke supplier).

### Job terjadwal (sinkron produk otomatis)

Sinkron katalog Digiflazz tiap 30 menit dijalankan penjadwal Laravel. Image
produksi hanya menjalankan Apache, jadi tambahkan cron di hosting:

```cron
* * * * * cd /path/ke/aplikasi && php artisan schedule:run >> /dev/null 2>&1
```

Tanpa cron ini aplikasi tetap jalan, tetapi produk/`desc` baru dari Digiflazz tidak
masuk sendiri (harus lewat tombol "Ambil harga terbaru" di konsol admin).

### Kalau preset Laravel tidak jalan

Ganti **build type** ke **Dockerfile** — `Dockerfile` di root sudah menyiapkan
Apache + PHP 8.4 (+ `pdo_pgsql`) + React build, dan `docker/entrypoint.sh`
otomatis menjalankan `php artisan migrate --force` + `db:seed` ke Postgres setiap
container start (jadi database kosong langsung terisi, tidak perlu perintah manual).
Port aplikasi tetap **80**:

```bash
docker build -t se-smart-pulsa .
docker run -d -p 80:80 \
  -e APP_KEY='base64:...' \
  -e DATABASE_URL='postgres://postgres:PASSWORD@db-se-smart-pulsa-db-6ae04923:5432/se-smart-pulsa-db' \
  -e COOKIE_SECURE=1 \
  -e SESSION_DRIVER=file -e CACHE_STORE=file \
  se-smart-pulsa
```

Cek log container: harus muncul `INFO Running migrations.` lalu Apache menyala.

## Cek setelah deploy

1. `https://domainmu.com/up` → 200.
2. `https://domainmu.com/api/v1/me` → 401 `{"message":"Sesi tidak ditemukan..."}`.
3. `https://domainmu.com/app` → halaman login; `/app/transaksi` juga tampil (bukan 404).
4. Halaman login **tidak** menampilkan kotak akun demo, dan
   `demo@sesmartpulsa.id` tidak bisa dipakai login (akun demo dilewati di produksi).
5. Atur sandi admin lewat tautan email: `php artisan admin:password-link <email-owner>`,
   lalu login sebagai admin → `/admin` terbuka.
6. Daftar akun baru dengan nomor WhatsApp aktif → kode OTP masuk → masuk → saldo Rp0 tampil.

> **Produksi tidak berisi data demo.** `DatabaseSeeder` melewati akun demo,
> riwayat demo, dan 95 produk simulasi saat `APP_ENV=production`, jadi katalog
> kosong sampai `php artisan digiflazz:sync-products` dijalankan dengan
> kredensial produksi. Database lama yang masih menyimpan produk `is_demo = true`
> (dari deploy sebelumnya) perlu dinonaktifkan sekali:
>
> ```sql
> UPDATE products SET active = false WHERE is_demo = true;
> ```

## Cek database baru terisi

Di tab **Deployments → App Logs** atau console project:

```bash
php artisan migrate:status      # tiga migration berstatus Ran
php artisan db:show             # driver pgsql, database se-smart-pulsa-db
```

## Backup database

`docker/backup-db.sh` membuat `pg_dump` terkompresi dan menghapus backup lebih tua
dari 14 hari:

```bash
BACKUP_DIR=/var/backups/pulsa ./docker/backup-db.sh
```

Cron harian di server (02.00):

```cron
0 2 * * * cd /path/ke/aplikasi && ./docker/backup-db.sh >> /var/log/pulsa-backup.log 2>&1
```

Salin hasilnya keluar dari VPS (rsync/S3) supaya tidak ikut hilang bila server
rusak, dan uji restore sekali sebelum go-live:

```bash
gunzip -c pulsa-20261029-020000.sql.gz | psql "$DATABASE_URL"
```

## Test otomatis

```bash
php artisan test          # atau: ./vendor/bin/phpunit
./vendor/bin/pint --test  # gaya kode PHP
```

Test berjalan tanpa database eksternal (sqlite in-memory, lihat `phpunit.xml`).

## Keamanan go-live

- Postgres **tidak boleh** dipublikasikan: tidak ada port `5432` yang di-map ke
  host/internet; aplikasi menghubunginya lewat jaringan internal Docker.
- `APP_ENV=production` + `APP_DEBUG=false`; `.env` tidak pernah masuk repo.
- `APP_KEY` dibuat khusus untuk deployment (`php artisan key:generate --show`),
  jangan memakai key sandbox. Mengganti `APP_KEY` membatalkan semua sesi dan
  membuat kredensial integrasi terenkripsi tidak terbaca (isi ulang lewat tab
  Integrasi).
- Ganti sandi database dari nilai contoh dan batasi SSH ke login key saja.
- Isi `TRUSTED_PROXIES` dengan IP proxy sendiri, jangan `*` bila server bisa
  diakses langsung tanpa proxy.
- Kredensial pihak ketiga hanya lewat env/dashboard atau tab **Integrasi**
  (tersimpan terenkripsi); jangan menulisnya di file repo.

## Catatan

- Frontend memakai path relatif `/api/v1`, jadi tidak ada CORS dan cookie sesi
  otomatis same-origin. Jangan set `VITE_API_BASE_URL` ke domain lain.
- Build React memakai `base: '/app/'`; kalau path `/app` diganti, ubah
  `web/vite.config.js` (`base`), `vite.config.js build.outDir`, dan `routes/web.php`.
- Data lama dari backend Node sebelumnya (database `pulsa` di sandbox) **tidak**
  dimigrasikan otomatis; Laravel memakai database baru dan seed demo sendiri.
