<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "notifikasi sudah dibaca" per pengguna.
 *
 * Notifikasi tidak disimpan sebagai tabel sendiri: isinya diturunkan dari data
 * yang sudah ada (transaksi gagal + pengembalian dana, penarikan saldo), lalu
 * dibandingkan dengan timestamp ini untuk menentukan titik merah dan status
 * belum dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('notifications_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_read_at');
        });
    }
};
