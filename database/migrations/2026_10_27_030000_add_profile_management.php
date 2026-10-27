<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nama file foto profil di storage lokal (disajikan lewat /api/v1/avatar/{file}).
            $table->string('avatar_path')->nullable()->after('phone_verified_at');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            // OTP dipakai untuk dua tujuan: verifikasi akun baru dan konfirmasi
            // perubahan nomor WhatsApp/email. `payload` menyimpan perubahan yang
            // baru diterapkan setelah kode benar.
            $table->string('purpose')->default('account_verification')->after('channel');
            $table->json('payload')->nullable()->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'payload']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
