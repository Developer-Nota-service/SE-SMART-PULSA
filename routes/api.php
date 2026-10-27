<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\MidtransController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Verifikasi akun lewat kode OTP WhatsApp (Fonnte).
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('resend-otp', [AuthController::class, 'resendOtp']);

    // Lupa sandi: verifikasi nomor WhatsApp dulu, lalu tampilkan email akun dan
    // atur sandi baru tanpa perlu tahu email lamanya.
    Route::post('password/forgot', [AuthController::class, 'requestPasswordReset']);
    Route::post('password/verify', [AuthController::class, 'verifyPasswordReset']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);

    // Webhook pembayaran Midtrans: publik, diverifikasi lewat signature_key.
    Route::post('midtrans/notification', [MidtransController::class, 'notification']);

    // Foto profil: nama file acak, dipakai langsung oleh <img>.
    Route::get('avatar/{file}', [ProfileController::class, 'avatar']);

    Route::middleware('session.token')->group(function () {
        Route::get('me', [AuthController::class, 'me']);

        // Pengaturan profil: nama, foto, kontak (OTP), tipe akun.
        Route::patch('profile', [ProfileController::class, 'updateName']);
        Route::post('profile/contact', [ProfileController::class, 'changeContact']);
        Route::post('profile/contact/verify', [ProfileController::class, 'verifyContact']);
        Route::patch('profile/account-type', [ProfileController::class, 'changeAccountType']);
        Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);

        Route::get('products', [CatalogController::class, 'index']);
        Route::get('products/{id}', [CatalogController::class, 'show']);

        Route::get('balance/entries', [WalletController::class, 'entries']);
        Route::get('deposits', [WalletController::class, 'deposits']);
        Route::post('deposits', [WalletController::class, 'storeDeposit']);

        // Cek status pembayaran langsung ke Midtrans (cadangan webhook).
        Route::post('midtrans/sync', [MidtransController::class, 'sync']);

        Route::get('transactions', [TransactionController::class, 'index']);
        Route::get('transactions/{id}', [TransactionController::class, 'show']);
        Route::post('transactions', [TransactionController::class, 'store']);
        Route::post('transactions/direct', [TransactionController::class, 'direct']);
    });
});
