# Deploy satu domain (Laravel + React + PostgreSQL)

Repo ini adalah **aplikasi Laravel** di root. React ada di `web/` dan di-build ke
`public/app`, lalu disajikan Laravel di `https://domainmu.com/app`.

| URL | Isi |
| --- | --- |
| `/` | redirect ke `/app` |
| `/app`, `/app/transaksi`, `/app/saldo`, ... | React (SPA) |
| `/api/v1/...` | API Laravel (JSON) |
| `/up` | healthcheck Laravel |

## Dokploy (framework Laravel)

| Setelan | Nilai |
| --- | --- |
| Framework | **Laravel** |
| Direktori akar | kosong (`/`) |
| Port aplikasi | **80** (kosong = 80). Jangan 8000 — itu port `artisan serve` di sandbox. |
| Build command | `composer install --no-dev --optimize-autoloader && npm run build` |
| Start command | `php artisan migrate --seed --force && php-fpm` (atau default preset) |

`npm run build` di root hanya membangun React ke `public/app` (lihat `package.json`).
`--seed` idempotent, jadi aman dijalankan setiap deploy.

### Environment wajib

```env
APP_NAME="SE SMART PULSA"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domainmu.com
APP_KEY=                # isi hasil: php artisan key:generate --show
DATABASE_URL=postgres://USER:PASSWORD@HOST:5432/DBNAME
DB_CONNECTION=pgsql
SESSION_DRIVER=file     # tabel `sessions` dipakai untuk token API
CACHE_STORE=file
QUEUE_CONNECTION=sync
COOKIE_SECURE=1
```

`DATABASE_URL` disalin dari service **PostgreSQL** di hosting (host internal, bukan
`localhost`). Buat database dulu, lalu deploy.

### Kalau preset Laravel tidak jalan

Ganti **build type** ke **Dockerfile** — `Dockerfile` di root sudah menyiapkan
Apache + PHP 8.4 + Postgres + React build. Port aplikasi tetap **80**:

```bash
docker build -t se-smart-pulsa .
docker run -d -p 80:80 \
  -e APP_KEY='base64:...' \
  -e DATABASE_URL='postgres://USER:PASSWORD@HOST:5432/DBNAME' \
  -e COOKIE_SECURE=1 \
  -e SESSION_DRIVER=file -e CACHE_STORE=file \
  se-smart-pulsa
docker exec <container> php artisan migrate --seed --force
```

## Cek setelah deploy

1. `https://domainmu.com/up` → 200.
2. `https://domainmu.com/api/v1/me` → 401 `{"message":"Sesi tidak ditemukan..."}`.
3. `https://domainmu.com/app` → halaman login; `/app/transaksi` juga tampil (bukan 404).
4. Login `demo@sesmartpulsa.id` / `demo12345` → saldo Rp186.900, 95 produk demo.
5. Daftar akun baru → masuk → saldo Rp0 tampil.

## Catatan

- Frontend memakai path relatif `/api/v1`, jadi tidak ada CORS dan cookie sesi
  otomatis same-origin. Jangan set `VITE_API_BASE_URL` ke domain lain.
- Build React memakai `base: '/app/'`; kalau path `/app` diganti, ubah
  `web/vite.config.js` (`base`), `vite.config.js build.outDir`, dan `routes/web.php`.
- Data lama dari backend Node sebelumnya (database `pulsa` di sandbox) **tidak**
  dimigrasikan otomatis; Laravel memakai database baru dan seed demo sendiri.
