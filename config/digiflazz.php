<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Digiflazz (buyer API)
    |--------------------------------------------------------------------------
    |
    | Kredensial diisi dari dashboard Base44 (tab Secrets), bukan di repo:
    | DIGIFLAZZ_USERNAME, DIGIFLAZZ_API_KEY, DIGIFLAZZ_WEBHOOK_SECRET.
    |
    | testing=true memakai mode development Digiflazz (transaksi disimulasikan).
    | Set DIGIFLAZZ_TESTING=false saat go-live.
    |
    */

    'url' => rtrim((string) env('DIGIFLAZZ_URL', 'https://api.digiflazz.com'), '/'),

    'username' => env('DIGIFLAZZ_USERNAME'),

    'api_key' => env('DIGIFLAZZ_API_KEY'),

    'webhook_secret' => env('DIGIFLAZZ_WEBHOOK_SECRET'),

    'testing' => filter_var(env('DIGIFLAZZ_TESTING', true), FILTER_VALIDATE_BOOL),

    // Margin harga reseller di atas harga supplier (persen), dibulatkan per Rp100.
    'margin_percent' => (float) env('DIGIFLAZZ_MARGIN_PERCENT', 5),

];
