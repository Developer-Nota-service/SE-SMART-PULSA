<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->bigInteger('amount');
            $table->string('channel_type');                 // bank | ewallet
            $table->string('provider_code');                // bca, gopay, …
            $table->string('provider_name');
            $table->string('account_number');
            $table->string('account_holder');               // nama yang diketik pengguna
            $table->string('verified_holder')->nullable();  // nama hasil cek API
            $table->string('verification_status')->default('unverified'); // matched | mismatch | unverified
            $table->string('status')->default('pending');   // pending | processing | paid | rejected
            $table->string('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
