<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reseller menjual ke pembeli di konter dengan harga sendiri. Harga jual itu
 * disimpan per transaksi supaya struk (cetak/WhatsApp) menampilkan harga jual
 * reseller, bukan harga modal dari aplikasi. `null` = pakai total bayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('sell_price')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('sell_price');
        });
    }
};
