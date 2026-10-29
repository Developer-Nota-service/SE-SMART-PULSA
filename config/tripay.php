<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TriPay PPOB (pembanding harga modal)
    |--------------------------------------------------------------------------
    |
    | Kredensial diisi dari dashboard Base44 (tab Secrets), bukan di repo:
    | TRIPAY_API_KEY. Kunci diambil di tripay.id → Profile → API & Callback.
    |
    | Mode sandbox memakai base URL https://tripay.id/api-sandbox/v2, produksi
    | https://tripay.id/api/v2 — ubah lewat TRIPAY_BASE_URL bila perlu.
    |
    */

    'base_url' => rtrim((string) env('TRIPAY_BASE_URL', 'https://tripay.id/api/v2'), '/'),

    'api_key' => env('TRIPAY_API_KEY'),

    // Daftar produk jarang berubah; simpan sebentar agar halaman banding harga
    // tidak menembak API supplier setiap kali dibuka.
    'cache_seconds' => (int) env('TRIPAY_CACHE_SECONDS', 300),

];
