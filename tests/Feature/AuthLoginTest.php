<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    public function test_health_endpoint_is_public(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_protected_endpoint_requires_a_session(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Email wajib diisi.')
            ->assertJsonPath('errors.password.0', 'Password wajib diisi.');
    }

    public function test_hashed_bearer_session_authenticates_without_storing_raw_token(): void
    {
        $this->createAuthTables();
        $token = str_repeat('a', 64);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => 'auth@example.test',
            'phone' => '081234567890',
            'role' => 'user',
            'account_type' => 'personal',
            'balance' => 0,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'avatar_path' => null,
        ]);

        DB::table('auth_sessions')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'created_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $userId);

        $this->assertSame(hash('sha256', $token), DB::table('auth_sessions')->value('token_hash'));
    }

    public function test_cookie_authenticated_write_requires_same_origin_header(): void
    {
        $this->createAuthTables();
        $token = str_repeat('b', 64);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Cookie User',
            'email' => 'cookie@example.test',
            'phone' => '081234567891',
            'role' => 'user',
            'account_type' => 'personal',
            'balance' => 0,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'avatar_path' => null,
        ]);

        DB::table('auth_sessions')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'created_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withCookie('sesp_session', $token)
            ->postJson('/api/v1/notifications/read', [])
            ->assertForbidden()
            ->assertJsonPath('message', 'Permintaan lintas asal ditolak.');
    }

    private function createAuthTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('role');
            $table->string('account_type');
            $table->bigInteger('balance')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('avatar_path')->nullable();
        });

        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
