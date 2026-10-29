<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banner beranda memakai gambar (JPG/PNG/WEBP) yang diunggah admin. Kolom
 * `tone` tetap dipakai sebagai tampilan cadangan untuk banner lama tanpa gambar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('subtitle');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
