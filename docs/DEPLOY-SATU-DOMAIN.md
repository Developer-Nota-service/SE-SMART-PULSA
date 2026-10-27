# Deploy satu domain (Laravel + React)

Tujuan: satu repo, satu deploy, satu domain.
`https://domainmu.com/` → tampilan React, `https://domainmu.com/api/v1/...` → Laravel.
Frontend memanggil API lewat path relatif `/api/v1`, jadi tidak perlu CORS.

Struktur akhir yang dituju:

```
/                      # Laravel di root repo (artisan, app/, routes/, config/, public/)
├── web/               # source React (Vite)
├── public/app/        # hasil build React (dibuat saat build, tidak di-commit)
└── api/               # API demo Express (tidak dipakai di produksi)
```

## 1. Unggah Laravel dari Termux ke branch baru

Dari folder proyek Laravel di HP:

```bash
cd ~/se-smart-pulsa
git checkout -b laravel-app
```

Pastikan file besar dan rahasia **tidak** ikut ter-commit:

```bash
git status --porcelain | grep -E 'vendor/|node_modules/|^.. \.env$|storage/'
```

Kalau ada yang muncul, tambahkan ke `.gitignore` lalu `git rm -r --cached <path>`.
`vendor/`, `node_modules/`, `.env`, `storage/*.log`, dan `public/storage` tidak boleh
masuk repo — `.env` berisi `APP_KEY` dan password database.

```bash
git add -A
git commit -m "feat(backend): import Laravel app"
git push -u origin laravel-app
```

## 2. Penggabungan (dikerjakan di repo ini)

Setelah branch `laravel-app` ada di GitHub:

1. Merge branch tersebut ke branch kerja, lalu susun ulang: file Laravel ke root repo
   (`artisan`, `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`,
   `routes/`, `composer.json`, `composer.lock`); `web/` tetap.
2. Tambahkan route fallback SPA di `routes/web.php`, setelah route API terdaftar:

   ```php
   Route::get('/{any?}', fn () => response()->file(public_path('app/index.html')))
       ->where('any', '^(?!api).*$');
   ```

3. Build React → `public/app/`, aset ke `public/assets/`.
4. Tambahkan `Dockerfile`/start command satu service + `.env.production.example`.

## 3. Setelan di platform deploy

| Setelan | Nilai |
| --- | --- |
| Framework | Laravel |
| Root directory | `/` |
| Build command | `composer install --no-dev --optimize-autoloader && npm --prefix web ci && npm --prefix web run build && mkdir -p public/app && cp -r web/dist/* public/app/ && cp -r public/app/assets public/assets` |
| Start command | `php artisan migrate --force && php artisan config:cache && php artisan serve --host 0.0.0.0 --port $PORT` |

Environment variable wajib:

```
APP_NAME="SE SMART PULSA"
APP_ENV=production
APP_DEBUG=false
APP_KEY=<php artisan key:generate --show>
APP_URL=https://domainmu.com
DB_CONNECTION=pgsql        # atau mysql, sesuai database platform
DB_HOST=... DB_PORT=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=...
```

Jangan pakai SQLite di platform: file database-nya hilang setiap deploy.

## 4. Cek setelah deploy

1. `https://domainmu.com/api/v1/me` → `{"message":"Unauthenticated."}` = API hidup.
2. Buka `/` → halaman login tampil (bukan 404 atau daftar file).
3. Daftar akun → masuk → nama tampil di kartu saldo.
4. Endpoint yang **belum ada** di Laravel: `/products`, `/transactions`,
   `/balance/entries`. Selama belum dibuat, Beranda menampilkan pesan gagal memuat
   produk dan riwayat kosong.
