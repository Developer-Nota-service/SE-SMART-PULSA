<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengembalian dana transaksi yang sudah dibayar lewat Midtrans tetapi gagal di
 * supplier (Digiflazz).
 *
 * `refund_status`: `processing` (sedang dicoba), `midtrans` (dana dikembalikan
 * ke metode pembayaran asal), `balance` (Midtrans menolak/metodenya tidak
 * mendukung refund, dana dikreditkan ke saldo pengguna), `failed` (keduanya
 * gagal; admin menindak lewat konsol).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('refund_status', 20)->nullable()->after('supplier_message');
            $table->text('refund_message')->nullable()->after('refund_status');
            $table->timestamp('refunded_at')->nullable()->after('refund_message');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['refund_status', 'refund_message', 'refunded_at']);
        });
    }
};
