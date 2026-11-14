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

// Kantor Agent AI (PWA mandiri) dibangun ke public/app/kantor dan dipasang di
// HP dari alamat pendek ini. Di dev Vite yang melayani /kantor/.
Route::get('/kantor', fn () => redirect('/app/kantor/'));
