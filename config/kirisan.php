<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kirisan Send API
    |--------------------------------------------------------------------------
    |
    | Dipakai bila MAIL_MAILER=kirisan (lihat app/Mail/Transport/KirisanTransport).
    | Token akun diambil dari dash.kirisan.com > Account > Settings, token
    | sender email dari Channel > Email > Emails.
    |
    */

    'url' => rtrim((string) env('KIRISAN_API_URL', 'https://api.kirisan.com'), '/'),

    'token' => env('KIRISAN_API_TOKEN'),

    'email_token' => env('KIRISAN_EMAIL_TOKEN'),

];
