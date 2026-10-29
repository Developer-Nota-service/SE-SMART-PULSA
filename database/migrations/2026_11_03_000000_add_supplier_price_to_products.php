<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga modal supplier disimpan terpisah dari harga jual supaya admin bisa
 * membandingkan modal dan mengatur harga sendiri dari konsol admin.
 *
 * `cost_price` = harga seller Digiflazz saat sinkron terakhir, `seller_name` =
 * provider yang mengirim produk itu. `max_cost_price` = batas harga modal yang
 * mau dibayar: dikirim sebagai `max_price` saat topup, sehingga transaksi
 * ditolak Digiflazz bila harga supplier naik melewati batas ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->bigInteger('cost_price')->nullable()->after('retail_price');
            $table->string('seller_name')->nullable()->after('cost_price');
            $table->bigInteger('max_cost_price')->nullable()->after('seller_name');
            $table->timestampTz('cost_synced_at')->nullable()->after('max_cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'seller_name', 'max_cost_price', 'cost_synced_at']);
        });
    }
};
