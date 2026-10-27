<?php

use Illuminate\Support\Facades\Route;

// The React app is built into public/app and served under /app. Its client-side
// routes (/transaksi, /saldo, ...) are all resolved to index.html.
$serveApp = function () {
    $index = public_path('app/index.html');

    abort_unless(is_file($index), 503, 'Frontend belum dibangun. Jalankan: npm run build');

    return response()->file($index);
};

Route::get('/', fn () => redirect('/app'));

Route::get('/app', $serveApp);
Route::get('/app/{any}', $serveApp)->where('any', '.*');
