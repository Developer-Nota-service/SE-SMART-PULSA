<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak pemenuhan transaksi di sisi supplier (Digiflazz): status, nomor seri,
 * dan pesan terakhirnya. `order_number` dipakai sebagai ref_id Digiflazz,
 * sehingga tidak perlu kolom referensi terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('supplier')->nullable()->after('payment_method');
            $table->string('supplier_status')->nullable()->after('supplier');
            $table->string('supplier_sn')->nullable()->after('supplier_status');
            $table->text('supplier_message')->nullable()->after('supplier_sn');
            $table->timestampTz('fulfilled_at')->nullable()->after('supplier_message');
            $table->index('supplier_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['supplier_status']);
            $table->dropColumn(['supplier', 'supplier_status', 'supplier_sn', 'supplier_message', 'fulfilled_at']);
        });
    }
};
