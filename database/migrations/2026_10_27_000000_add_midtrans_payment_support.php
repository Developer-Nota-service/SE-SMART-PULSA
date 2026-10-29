<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Harga publik (bayar langsung via Midtrans). Kolom `price` tetap
            // dipakai sebagai harga saldo/reseller yang lebih murah.
            $table->bigInteger('retail_price')->default(0)->after('price');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_method')->default('balance')->after('status');
            $table->timestampTz('paid_at')->nullable()->after('payment_method');
        });

        DB::table('products')
            ->select('id', 'price')
            ->orderBy('id')
            ->chunk(200, function ($products): void {
                foreach ($products as $product) {
                    DB::table('products')->where('id', $product->id)->update([
                        'retail_price' => $this->retailPrice((int) $product->price),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'paid_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('retail_price');
        });
    }

    private function retailPrice(int $price): int
    {
        return (int) (ceil($price * 1.05 / 100) * 100);
    }
};
