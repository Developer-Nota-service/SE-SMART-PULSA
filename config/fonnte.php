<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fonnte (WhatsApp Gateway)
    |--------------------------------------------------------------------------
    |
    | Token Fonnte dipakai untuk mengirim kode OTP WhatsApp. Ambil token di
    | dashboard Fonnte (fonnte.com > Device > Token). Tanpa token, kode OTP
    | tidak terkirim dan pendaftaran tidak bisa diselesaikan.
    |
    */

    'token' => env('FONNTE_TOKEN'),

    'url' => env('FONNTE_API_URL', 'https://api.fonnte.com/send'),

];
