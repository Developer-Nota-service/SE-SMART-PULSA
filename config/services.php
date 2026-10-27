<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', '0') === '1',
        'finish_url' => env('MIDTRANS_FINISH_URL'),
    ],

    // Pengecekan nama pemilik rekening/e-wallet tujuan penarikan (Identity
    // Iluma). Basic auth: API key sebagai username; lihat
    // App\Services\BankAccountVerifier.
    'iluma' => [
        'api_key' => env('ILUMA_API_KEY'),
        'base_url' => env('ILUMA_BASE_URL', 'https://api.iluma.ai'),
        'timeout' => (int) env('ILUMA_TIMEOUT', 20),
    ],

];
