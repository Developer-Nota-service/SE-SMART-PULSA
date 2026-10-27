<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tipe akun dipilih saat registrasi dan menentukan harga produk:
            // `reseller` memakai products.price, `personal` memakai retail_price.
            // Akun lama tetap reseller agar harga yang terlihat tidak berubah.
            $table->string('account_type')->default('reseller')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
