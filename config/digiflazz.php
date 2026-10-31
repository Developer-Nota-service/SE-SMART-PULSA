<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Digiflazz (buyer API)
    |--------------------------------------------------------------------------
    |
    | Kredensial diisi melalui environment atau konsol admin.
    | Mode development hanya aktif secara default di luar produksi.
    |
    */

    'url' => rtrim((string) env('DIGIFLAZZ_URL', 'https://api.digiflazz.com'), '/'),

    'username' => env('DIGIFLAZZ_USERNAME'),

    'api_key' => env('DIGIFLAZZ_API_KEY'),

    'webhook_secret' => env('DIGIFLAZZ_WEBHOOK_SECRET'),

    'testing' => filter_var(
        env('DIGIFLAZZ_TESTING', env('APP_ENV', 'production') === 'production' ? false : true),
        FILTER_VALIDATE_BOOL,
    ),

    // Margin harga reseller di atas harga supplier (persen), dibulatkan per Rp100.
    'margin_percent' => (float) env('DIGIFLAZZ_MARGIN_PERCENT', 5),

];
