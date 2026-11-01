# Deployment produksi Rocky Linux + Docker

## Topologi

- Apache/PHP 8.4 + React build: container `app`, hanya dipublikasikan ke host loopback `127.0.0.1:8080`.
- PostgreSQL 16 dan Redis 7: jaringan Docker internal, tanpa port publik.
- Migrasi + seeder produksi: satu kali melalui service `migrate`.
- Queue worker dan Laravel scheduler: proses terpisah dari web.
- Data PostgreSQL, Redis, dan storage Laravel memakai named volumes.

## Persiapan server

1. Salin `.env.production.example` menjadi `.env`.
2. Isi domain final, APP_KEY, password database yang kuat, REDIS_PASSWORD yang berbeda, serta kredensial live Midtrans, Digiflazz, dan Fonnte.
3. Pastikan `.env` tidak pernah di-commit dan batasi aksesnya ke root.
4. Pastikan DNS domain mengarah ke IP VPS. Konfigurasikan reverse proxy TLS pada host ke `127.0.0.1:8080`; jangan publikasikan PostgreSQL atau Redis.
5. Daftarkan URL webhook live:
   - Midtrans: `https://DOMAIN/api/v1/midtrans/notification`
   - Digiflazz: `https://DOMAIN/api/v1/digiflazz/webhook`
6. Pastikan IP publik VPS sudah di-whitelist Digiflazz dan akun Midtrans benar-benar memiliki Live Server Key.

## Deployment pertama

Dari root repository:

```sh
docker compose --env-file .env -f compose.production.yaml config
docker compose --env-file .env -f compose.production.yaml build
docker compose --env-file .env -f compose.production.yaml up -d
```

Service `migrate` menunggu PostgreSQL dan Redis sehat, lalu menjalankan migrasi, seeder yang aman untuk production, dan optimasi Laravel. Web, worker, dan scheduler baru berjalan setelah migrasi sukses.

## Pemeriksaan

```sh
docker compose --env-file .env -f compose.production.yaml ps
docker compose --env-file .env -f compose.production.yaml logs --tail=100 app worker scheduler migrate
curl -fsS http://127.0.0.1:8080/up
```

Jangan jalankan `docker compose down -v` pada produksi: opsi `-v` menghapus volume database.

## Backup

Script `docker/backup-db.sh` membuat dump lokal dan menerapkan retensi 14 hari. Backup lokal bukan backup off-site; salin backup terenkripsi ke penyimpanan terpisah dan uji pemulihannya secara berkala sebelum menerima transaksi nyata.

## Mode pembayaran live

Konfigurasi menetapkan `MIDTRANS_IS_PRODUCTION=true` dan `DIGIFLAZZ_TESTING=false`. Nilai ini tidak menggantikan verifikasi kredensial: gunakan Live Server Key Midtrans (bukan `SB-`), Buyer API Key Digiflazz produksi, webhook secret, dan IP whitelist. Lakukan transaksi bernilai kecil setelah webhook dan rekonsiliasi status diverifikasi. Jangan pernah menguji transaksi uang nyata memakai akun pelanggan tanpa persetujuan dan kontrol operasional.

## Catatan

File ini menjelaskan konfigurasi container. TLS, domain, firewall host, SMTP, kredensial live, backup off-site, dan pemulihan harus diselesaikan serta diuji di VPS sebelum go-live.
