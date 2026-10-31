# Deployment satu domain

Aplikasi melayani frontend React dan API Laravel pada satu domain:

| URL | Isi |
| --- | --- |
| `/` | Redirect ke `/app` |
| `/app` dan turunannya | React SPA |
| `/api/v1/*` | Laravel API |
| `/up` | Health check Laravel |

Untuk deployment langsung pada VPS Ubuntu, ikuti panduan utama:
[DEPLOY-VPS-UBUNTU.md](DEPLOY-VPS-UBUNTU.md).

## Web server

Nginx harus menunjuk document root ke `/var/www/se-smart-pulsa/public`.
Konfigurasi siap pakai ada di
[`deploy/nginx/se-smart-pulsa.conf](../deploy/nginx/se-smart-pulsa.conf).
Frontend harus dibuild dengan:

```bash
npm ci --prefix web
npm run build --prefix web
```

Vite menempatkan hasil build di `public/app`; Laravel tetap menangani API dan
fallback route SPA. Jangan arahkan document root ke root repository.

## Environment

Gunakan [`.env.production.example`](../.env.production.example) sebagai daftar
variabel. Isi domain final pada APP_URL dan MIDTRANS_FINISH_URL. Simpan semua
secret hanya di file `.env` pada server; jangan commit secret ke Git.

## Integrasi pembayaran dan supplier

- Midtrans notification URL: `https://domain-anda.example/api/v1/midtrans/notification`
- Digiflazz webhook URL: `https://domain-anda.example/api/v1/digiflazz/webhook`

Aktifkan mode live hanya setelah kredensial produksi terverifikasi dan seluruh
uji sandbox berhasil. Detail pengujian dan deployment ada pada panduan VPS.
