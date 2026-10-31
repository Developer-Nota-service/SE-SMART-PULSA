<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->index(['user_id', 'expires_at']);
        });

        // Revoke legacy sessions before the application switches to hashed tokens.
        // The old sessions table is retained empty for safe rollback compatibility.
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
