<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->default('');
            $table->string('password_hash');
            $table->string('role')->default('user');
            $table->bigInteger('balance')->default(0);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('token')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at');
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('identifier');
            $table->boolean('succeeded');
            $table->timestampTz('attempted_at')->useCurrent();
            $table->index(['identifier', 'attempted_at']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('category');
            $table->string('operator');
            $table->string('name');
            $table->bigInteger('price');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('is_demo')->default(true);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('order_number')->unique();
            $table->string('customer_number');
            $table->bigInteger('amount');
            $table->string('status')->default('pending');
            $table->string('idempotency_key')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reference');
            $table->string('description');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->bigInteger('amount');
            $table->string('status')->default('pending');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('paid_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->json('detail')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('balance_entries');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('products');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
