# Pendaftaran Midtrans Iris (Payouts) — validasi nama rekening + pencairan dana

Catatan riset (Okt 2026): **Iris** sekarang disebut **Payouts** di dokumentasi
Midtrans. Iris dipakai untuk dua hal sekaligus: memvalidasi rekening tujuan
(termasuk nama pemilik) dan mengirim dana ke rekening/e-wallet.

- Base URL sandbox: `https://app.sandbox.midtrans.com/iris/api/v1/`
- Base URL production: `https://app.midtrans.com/iris/api/v1/`
- Auth: HTTP Basic, username = **Iris API Key**, password kosong
- Endpoint validasi rekening: `GET /account_validation?bank=bca&account=1234567890`
  → berisi nama pemilik rekening bila rekening valid

## 0. Prasyarat

1. Akun **Midtrans merchant aktif** dengan Payment Gateway yang sudah berjalan.
   Iris tidak bisa diaktifkan untuk merchant yang belum punya PG aktif.
2. Dokumen yang disiapkan:
   - KTP pemilik/pengurus
   - NPWP (pribadi atau perusahaan)
   - NIB / izin usaha (bila ada badan usaha)
   - Rekening bank atas nama merchant (untuk skema facilitator)
   - Email bisnis + nomor HP aktif
3. Pilih skema:
   - **Aggregator** — saldo deposit di Midtrans (top-up dulu); onboarding lebih cepat.
   - **Facilitator** — rekening bank sendiri sebagai sumber dana; onboarding lebih
     lama karena rekening harus didaftarkan ke bank dan Midtrans.

## 1. Ajukan aktivasi Iris

1. Kirim email ke **iris@midtrans.com**, atau lewat form
   `midtrans.com/contact-us/irispayouts-service-information/other-question-1`.
2. Sebutkan:
   - Merchant ID Midtrans yang sudah aktif
   - Skema yang dipilih (aggregator / facilitator)
   - Perkiraan volume dan nominal transaksi per bulan
   - Kebutuhan: validasi rekening (`account_validation`) dan/atau pencairan dana
3. Lampirkan dokumen pada poin 0.
4. Midtrans mengirim **PKS/adendum Iris** (biaya per transaksi, limit harian,
   kewajiban pelaporan). Setelah ditandatangani, akun Iris diaktifkan.

> Biaya per inquiry/payout dan limit tidak dipublikasikan — angkanya ada di PKS
> yang dikirim sales. Jangan mengasumsikan gratis.

## 2. Minta akun sandbox

Setelah PKS disetujui, minta **Iris sandbox account** ke Sales PIC / support
Midtrans. Sandbox punya API key dan saldo simulasi sendiri, terpisah dari
production.

## 3. Ambil API key & atur dashboard

1. Login **Merchant Administration Portal (MAP)** → menu **Iris / Payouts**.
2. Ambil **Iris API Key** untuk sandbox dan production.
3. Atur **Notification URL** (callback status payout); di production perubahan URL
   lewat support.
4. Atur peran user: **Maker** (membuat payout) dan **Approver** (menyetujui).
   Payout production butuh persetujuan approver.
5. Skema aggregator: lakukan top-up saldo deposit lebih dulu.

## 4. Uji validasi rekening di sandbox

```bash
curl -u "$MIDTRANS_IRIS_API_KEY:" \
  "https://app.sandbox.midtrans.com/iris/api/v1/account_validation?bank=bca&account=1234567890"
```

- Rekening valid → respons memuat nama pemilik rekening.
- Rekening tidak valid → tidak ada nama; anggap gagal, jangan kirim dana.
- Simpan hasil validasi agar endpoint tidak ditembak berulang untuk rekening sama.

## 5. Rencana integrasi ke aplikasi ini

- Secret yang dibutuhkan: `MIDTRANS_IRIS_API_KEY` (+ penanda sandbox/production).
- Alur penarikan: user isi rekening → backend panggil `account_validation` →
  nama dari bank disimpan sebagai `verified_holder` → status `verified`, atau
  `mismatch` bila beda dengan nama yang diketik user.
- Bila key belum ada, alur tetap seperti sekarang: input manual,
  `verification_status = unverified`, dicek admin sebelum dana dikirim.
- Pencairan dana (payout) tetap langkah terpisah setelah konfirmasi admin.

## Catatan kepatuhan

Nama pemilik rekening adalah data pribadi. Pastikan pengguna menyetujui pemakaian
data rekeningnya untuk verifikasi (syarat layanan atau persetujuan saat mengajukan
penarikan) sebelum fitur ini diaktifkan.
