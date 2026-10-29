<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // Tujuan kode tidak lagi selalu nomor WhatsApp: perubahan email
            // mengirim kode ke alamat email baru.
            $table->renameColumn('phone', 'target');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->renameColumn('target', 'phone');
        });
    }
};
