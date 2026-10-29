<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo ber-kode bisa memotong harga produk. Persentase potongan diatur admin di
 * konsol Beranda; transaksi menyimpan kode + nominal potongan yang benar-benar
 * dipakai supaya riwayat dan pengembalian dana tetap sesuai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->unsignedSmallInteger('discount_percent')->default(0);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('promo_code', 24)->nullable();
            $table->unsignedBigInteger('discount_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['promo_code', 'discount_amount']);
        });

        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
