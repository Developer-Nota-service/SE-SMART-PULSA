<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher inject (voucher Telkomsel) dikirim sebagai kode yang harus di-inject
 * sendiri ke voucher kosong. Nomor voucher kosong yang diisi pembeli disimpan
 * di sini supaya bisa dicocokkan saat transaksi diproses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('voucher_number', 32)->nullable()->after('customer_number');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('voucher_number');
        });
    }
};
