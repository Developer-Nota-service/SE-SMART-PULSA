<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateSessionToken;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_browser_login_uses_http_only_cookie_without_returning_bearer_token(): void
    {
        $this->createAuthTables();

        DB::table('users')->insert([
            'name' => 'Browser User',
            'email' => 'browser@example.test',
            'phone' => '081234567892',
            'password_hash' => Hash::make('correct-password'),
            'role' => 'user',
            'account_type' => 'personal',
            'balance' => 0,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'avatar_path' => null,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'browser@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('data.token')
            ->assertCookie('sesp_session');

        $this->assertSame(1, DB::table('auth_sessions')->count());
        $this->assertSame(64, strlen((string) DB::table('auth_sessions')->value('token_hash')));
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

        $request = Request::create(
            '/api/v1/notifications/read',
            'POST',
            [],
            ['sesp_session' => $token],
        );

        $response = app(AuthenticateSessionToken::class)->handle(
            $request,
            fn () => response()->json(['ok' => true]),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Permintaan lintas asal ditolak.', $response->getData(true)['message']);
    }

    protected function tearDown(): void
    {
        // These tests build a minimal schema manually. Remove it so the
        // PostgreSQL integration suite can run the real Laravel migrations.
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function createAuthTables(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('password_hash')->nullable();
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

        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('identifier');
            $table->boolean('succeeded');
            $table->timestamp('attempted_at');
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
