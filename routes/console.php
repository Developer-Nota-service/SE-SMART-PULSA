<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Produk baru di Digiflazz (dan kode produk yang berubah) otomatis masuk ke
// katalog tanpa admin menekan "Ambil harga terbaru". Dijalankan oleh service
// cron server menjalankan `php artisan schedule:run` tiap menit.
Schedule::command('digiflazz:sync-products')
    ->everyThirtyMinutes()
    // Kunci dilepas 10 menit setelah mulai, bukan 24 jam, supaya tarikan yang
    // terputus tidak mematikan jadwal seharian.
    ->withoutOverlapping(10);
