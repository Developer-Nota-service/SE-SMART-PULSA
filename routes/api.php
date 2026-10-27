<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\MidtransController;
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

    // Webhook pembayaran Midtrans: publik, diverifikasi lewat signature_key.
    Route::post('midtrans/notification', [MidtransController::class, 'notification']);

    Route::middleware('session.token')->group(function () {
        Route::get('me', [AuthController::class, 'me']);

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
