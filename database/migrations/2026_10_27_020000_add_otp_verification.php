<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Akun baru hanya boleh masuk setelah email & nomor WhatsApp
            // terverifikasi lewat kode OTP.
            $table->timestampTz('email_verified_at')->nullable()->after('phone');
            $table->timestampTz('phone_verified_at')->nullable()->after('email_verified_at');
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->default('whatsapp');
            $table->string('phone');
            // Hanya hash kode yang disimpan; kode asli tidak pernah masuk database.
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'phone_verified_at']);
        });
    }
};
