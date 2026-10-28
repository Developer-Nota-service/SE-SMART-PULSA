<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Voucher Telkomsel (produk inject) dipindah dari kategori `voucher` ke
 * `voucher_inject` supaya punya halaman sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->where('category', 'voucher')
            ->whereRaw('lower(operator) like ?', ['%telkomsel%'])
            ->update(['category' => 'voucher_inject']);
    }

    public function down(): void
    {
        DB::table('products')
            ->where('category', 'voucher_inject')
            ->update(['category' => 'voucher']);
    }
};
