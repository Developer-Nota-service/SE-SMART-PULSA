<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Petunjuk input dari seller Digiflazz (`desc` pada daftar harga). Isinya
 * menentukan apa yang harus diketik pembeli ke `customer_no`, mis.
 * "no pelanggan = gabungan antara user_id dan zone_id" atau
 * "Format no tujuan [UID]|[Server]" untuk produk game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('instruction')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('instruction');
        });
    }
};
