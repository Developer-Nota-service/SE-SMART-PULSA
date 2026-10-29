<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya layanan Midtrans disimpan per pesanan supaya nominal yang ditagih
 * (nilai pesanan + biaya) bisa dicocokkan lagi saat webhook datang, dan saldo
 * yang dikreditkan tetap hanya nilai pesanan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table): void {
            $table->unsignedBigInteger('fee_amount')->default(0)->after('amount');
            $table->string('payment_channel', 20)->nullable()->after('fee_amount');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('fee_amount')->default(0)->after('amount');
            $table->string('payment_channel', 20)->nullable()->after('fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropColumn(['fee_amount', 'payment_channel']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['fee_amount', 'payment_channel']);
        });
    }
};
