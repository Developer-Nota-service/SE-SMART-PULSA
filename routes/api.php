<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DigiflazzWebhookController;
use App\Http\Controllers\Api\MidtransController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    // Batas per IP (per menit) untuk endpoint publik: sandi tidak bisa
    // di-brute-force lintas akun dan kode OTP tidak bisa dipicu berulang
    // (tiap kiriman OTP memakai kuota Fonnte). Login tetap punya batas
    // tambahan 5 percobaan gagal / 15 menit per email di AuthController.
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
    });

    // Verifikasi akun lewat kode OTP WhatsApp (Fonnte).
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    });

    // Lupa sandi: verifikasi nomor WhatsApp dulu, lalu tampilkan email akun dan
    // atur sandi baru tanpa perlu tahu email lamanya.
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('resend-otp', [AuthController::class, 'resendOtp']);
        Route::post('password/forgot', [AuthController::class, 'requestPasswordReset']);
        Route::post('password/reset', [AuthController::class, 'resetPassword']);
    });

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('password/verify', [AuthController::class, 'verifyPasswordReset']);
    });

    // Webhook pembayaran Midtrans: publik, diverifikasi lewat signature_key.
    Route::post('midtrans/notification', [MidtransController::class, 'notification']);

    // Webhook status transaksi Digiflazz: publik, diverifikasi lewat HMAC SHA1.
    Route::post('digiflazz/webhook', [DigiflazzWebhookController::class, 'handle']);

    // Foto profil: nama file acak, dipakai langsung oleh <img>.
    Route::get('avatar/{file}', [ProfileController::class, 'avatar']);

    // Gambar banner beranda: nama file acak, dipakai langsung oleh <img>.
    Route::get('banner/{file}', [DashboardController::class, 'banner']);

    // Metode pembayaran Midtrans + tarif biaya layanannya (config midtrans_fees).
    Route::get('payments/methods', [PaymentController::class, 'methods']);

    // Konsol admin: pantau & selesaikan deposit, transaksi, dan penarikan.
    Route::middleware(['session.token', 'admin.only'])->prefix('admin')->group(function () {
        Route::get('overview', [AdminController::class, 'overview']);
        Route::get('withdrawals', [AdminController::class, 'withdrawals']);
        Route::post('withdrawals/{id}', [AdminController::class, 'updateWithdrawal']);
        Route::get('deposits', [AdminController::class, 'deposits']);
        Route::post('deposits/{id}/sync', [AdminController::class, 'syncDeposit']);
        Route::post('deposits/{id}/cancel', [AdminController::class, 'cancelDeposit']);
        Route::get('products', [AdminController::class, 'products']);
        Route::post('products/bulk-price', [AdminController::class, 'bulkPrice']);
        // Tarik harga modal terbaru dari Digiflazz (harga jual admin tetap).
        Route::post('products/sync-digiflazz', [AdminController::class, 'syncDigiflazz']);
        Route::patch('products/{id}', [AdminController::class, 'updateProduct']);
        // Konten beranda pengguna: banner, promo, dan peringkat pembelian.
        Route::get('dashboard', [AdminDashboardController::class, 'index']);
        Route::post('banners', [AdminDashboardController::class, 'storeBanner']);
        // POST untuk unggah gambar (multipart hanya terbaca pada POST), PATCH untuk ubahan JSON.
        Route::post('banners/{id}', [AdminDashboardController::class, 'updateBanner']);
        Route::patch('banners/{id}', [AdminDashboardController::class, 'updateBanner']);
        Route::delete('banners/{id}', [AdminDashboardController::class, 'destroyBanner']);
        Route::post('promos', [AdminDashboardController::class, 'storePromo']);
        Route::patch('promos/{id}', [AdminDashboardController::class, 'updatePromo']);
        Route::delete('promos/{id}', [AdminDashboardController::class, 'destroyPromo']);
        Route::patch('dashboard-settings', [AdminDashboardController::class, 'updateSettings']);
        Route::get('transactions', [AdminController::class, 'transactions']);
        Route::post('transactions/{id}/check', [AdminController::class, 'checkTransaction']);
        Route::post('transactions/{id}/refund', [AdminController::class, 'markRefundPaid']);
        Route::get('users', [AdminController::class, 'users']);
    });

    Route::middleware('session.token')->group(function () {
        Route::get('me', [AuthController::class, 'me']);

        // Pemberitahuan akun: transaksi gagal + status pengembalian dana, dan
        // status penarikan saldo.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'read']);

        // Pengaturan profil: nama, foto, kontak (OTP), tipe akun.
        Route::patch('profile', [ProfileController::class, 'updateName']);
        Route::post('profile/contact', [ProfileController::class, 'changeContact']);
        Route::post('profile/contact/verify', [ProfileController::class, 'verifyContact']);
        Route::patch('profile/account-type', [ProfileController::class, 'changeAccountType']);
        Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);

        // Konten beranda: banner, promo aktif, peringkat pembelian otomatis.
        Route::get('dashboard', [DashboardController::class, 'show']);

        Route::get('products', [CatalogController::class, 'index']);
        Route::get('products/{id}', [CatalogController::class, 'show']);

        Route::get('balance/entries', [WalletController::class, 'entries']);
        Route::get('deposits', [WalletController::class, 'deposits']);
        Route::post('deposits', [WalletController::class, 'storeDeposit']);

        // Penarikan saldo: daftar bank/e-wallet dan pengajuan.
        Route::get('withdrawals/channels', [WithdrawalController::class, 'channels']);
        Route::get('withdrawals', [WithdrawalController::class, 'index']);
        Route::post('withdrawals', [WithdrawalController::class, 'store']);

        // Cek status pembayaran langsung ke Midtrans (cadangan webhook).
        Route::post('midtrans/sync', [MidtransController::class, 'sync']);

        Route::get('transactions', [TransactionController::class, 'index']);
        Route::get('transactions/{id}', [TransactionController::class, 'show']);
        // Harga jual di struk milik reseller (pembeli tidak melihat harga modal).
        Route::patch('transactions/{id}/sell-price', [TransactionController::class, 'updateSellPrice']);
        Route::post('transactions', [TransactionController::class, 'store']);
        Route::post('transactions/direct', [TransactionController::class, 'direct']);
        // Cek kode promo sebelum membeli; nominal tetap dihitung ulang saat transaksi.
        Route::post('promos/validate', [TransactionController::class, 'validatePromo']);
    });
});
