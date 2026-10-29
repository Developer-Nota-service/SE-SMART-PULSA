<?php

/*
|--------------------------------------------------------------------------
| Biaya layanan Midtrans per metode pembayaran
|--------------------------------------------------------------------------
|
| Midtrans memotong biayanya dari dana yang disettlekan ke merchant, jadi
| nominal tagihan dinaikkan (gross-up) supaya dana bersih yang diterima sama
| dengan nilai pesanan:
|
|   total  = ceil((nilai + flat) / (1 - persen/100))
|   biaya  = total - nilai
|
| Sesuaikan `percent`/`flat` dengan tarif di dashboard Midtrans Anda
| (Settings > Fees) — tarif tiap merchant bisa berbeda. `enabled_payments`
| adalah kode metode Snap yang dibuka untuk kanal tersebut; daftar lengkapnya
| ada di dokumentasi Midtrans "Snap > Enabled Payments".
|
| Deposit reseller maupun pembelian langsung akun pribadi memakai tabel ini
| lewat App\Support\PaymentFee.
|
*/

return [

    'channels' => [

        'qris' => [
            'label' => 'QRIS (semua bank & e-wallet)',
            'percent' => 0.7,
            'flat' => 0,
            'enabled_payments' => ['other_qris'],
        ],

        'gopay' => [
            'label' => 'GoPay',
            'percent' => 2.0,
            'flat' => 0,
            'enabled_payments' => ['gopay'],
        ],

        'shopeepay' => [
            'label' => 'ShopeePay',
            'percent' => 2.0,
            'flat' => 0,
            'enabled_payments' => ['shopeepay'],
        ],

        'va' => [
            'label' => 'Virtual Account (BCA/BNI/BRI/Permata/CIMB)',
            'percent' => 0,
            'flat' => 4000,
            'enabled_payments' => [
                'bca_va',
                'bni_va',
                'bri_va',
                'permata_va',
                'cimb_va',
                'other_va',
            ],
        ],

        'cstore' => [
            'label' => 'Alfamart / Indomaret',
            'percent' => 0,
            'flat' => 5000,
            'enabled_payments' => ['alfamart', 'indomaret'],
        ],

        'credit_card' => [
            'label' => 'Kartu kredit / debit',
            'percent' => 2.9,
            'flat' => 2000,
            'enabled_payments' => ['credit_card'],
        ],

    ],

];
