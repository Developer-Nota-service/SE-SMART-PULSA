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

> **Catatan branch:** auto-deploy mengikuti branch `main`, dan `main` **sudah**
> berisi kode Laravel (root repo: `artisan`, `app/`, `routes/api.php`) — backend
> Node lama (`api/`) sudah tidak ada, jadi tidak perlu merge branch lagi.

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
TRUSTED_PROXIES=*                           # TLS diterminasi proxy hosting
```

Catatan: `APP_URL` harus domain final (dengan `https://`). Redirect `/` → `/app`
mengikuti skema request berkat `TRUSTED_PROXIES`, jadi jangan hapus baris itu
selama TLS masih diterminasi di proxy hosting.

Alternatif: isi `DATABASE_URL` saja (format `postgres://USER:PASSWORD@HOST:5432/DB`).

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

## Pasang domain (DNS)

Repo tidak menyimpan domain — pengikatan domain ada di dashboard hosting + DNS,
jadi langkah ini butuh akses akun pemilik:

1. DockHosting → project `se-smart-pulsa` → tab **Domains**, tambahkan domain
   (mis. `sesmartpulsa.id`) dan catat target (IP/hostname) yang ditampilkan.
2. Di panel DNS registrar:

   | Tipe | Nama | Nilai |
   | --- | --- | --- |
   | `A` | `@` | IP target dari hosting |
   | `CNAME` | `www` | hostname project di hosting |

3. Cek propagasi: `dig +short sesmartpulsa.id` → harus IP hosting. Lalu aktifkan
   **SSL/HTTPS** di tab Domains (Let's Encrypt) sampai `https://` valid —
   `COOKIE_SECURE=1` butuh HTTPS atau cookie login tidak terkirim.
4. Set `APP_URL=https://sesmartpulsa.id` di tab **Environment**, redeploy, lalu
   jalankan checklist di bawah.

Kalau domain sudah terpasang di hosting, cukup pastikan `APP_URL` sama persis
dengan domain tersebut (skema + host, tanpa `/` di akhir).

## Cek setelah deploy

1. `https://domainmu.com/up` → 200.
2. `https://domainmu.com/api/v1/me` → 401 `{"message":"Sesi tidak ditemukan..."}`.
3. `https://domainmu.com/app` → halaman login; `/app/transaksi` juga tampil (bukan 404).
4. Login `demo@sesmartpulsa.id` / `demo12345` → saldo Rp186.900, 95 produk demo.
5. Daftar akun baru → masuk → saldo Rp0 tampil.

## Cek database baru terisi

Di tab **Deployments → App Logs** atau console project:

```bash
php artisan migrate:status      # tiga migration berstatus Ran
php artisan db:show             # driver pgsql, database se-smart-pulsa-db
```

## Catatan

- Frontend memakai path relatif `/api/v1`, jadi tidak ada CORS dan cookie sesi
  otomatis same-origin. Jangan set `VITE_API_BASE_URL` ke domain lain.
- Build React memakai `base: '/app/'`; kalau path `/app` diganti, ubah
  `web/vite.config.js` (`base`), `vite.config.js build.outDir`, dan `routes/web.php`.
- Data lama dari backend Node sebelumnya (database `pulsa` di sandbox) **tidak**
  dimigrasikan otomatis; Laravel memakai database baru dan seed demo sendiri.
