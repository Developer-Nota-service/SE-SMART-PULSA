<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    private const SESSION_DAYS = 7;

    private const MAX_FAILED_ATTEMPTS = 5;

    private const ATTEMPT_WINDOW_MINUTES = 15;

    public function register(Request $request): JsonResponse
    {
        $input = [
            'name' => trim((string) $request->input('name')),
            'email' => strtolower(trim((string) $request->input('email'))),
            'phone' => preg_replace('/\D/', '', (string) $request->input('phone')),
            'password' => (string) $request->input('password'),
            'accountType' => (string) $request->input('accountType', 'reseller'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'min:2'],
            'email' => ['required', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/'],
            'phone' => ['required', 'regex:/^0\d{8,14}$/'],
            'password' => ['required', 'min:8'],
            'accountType' => ['in:reseller,personal'],
        ], [
            'name.required' => 'Nama minimal 2 karakter.',
            'name.min' => 'Nama minimal 2 karakter.',
            'email.required' => 'Format email tidak valid.',
            'email.regex' => 'Format email tidak valid.',
            'phone.required' => 'Nomor HP diawali 0 dan 9-15 digit.',
            'phone.regex' => 'Nomor HP diawali 0 dan 9-15 digit.',
            'password.required' => 'Password minimal 8 karakter.',
            'password.min' => 'Password minimal 8 karakter.',
            'accountType.in' => 'Pilih tipe akun reseller atau pribadi.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Data pendaftaran belum lengkap.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        if (DB::table('users')->where('email', $input['email'])->exists()) {
            return response()->json([
                'message' => 'Email sudah terdaftar.',
                'errors' => ['email' => ['Email sudah terdaftar.']],
            ], 422);
        }

        $id = DB::table('users')->insertGetId([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'password_hash' => Hash::make($input['password']),
            'role' => 'user',
            'account_type' => $input['accountType'],
            'balance' => 0,
            'created_at' => now(),
        ]);

        $user = DB::table('users')
            ->select('id', 'name', 'email', 'phone', 'role', 'account_type', 'balance')
            ->find($id);

        $this->writeAudit($id, 'auth.register');

        return $this->sessionResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return response()->json([
                'message' => 'Email dan password wajib diisi.',
                'errors' => array_filter([
                    'email' => $email === '' ? ['Email wajib diisi.'] : null,
                    'password' => $password === '' ? ['Password wajib diisi.'] : null,
                ]),
            ], 422);
        }

        $failed = DB::table('login_attempts')
            ->where('identifier', $email)
            ->where('succeeded', false)
            ->where('attempted_at', '>', now()->subMinutes(self::ATTEMPT_WINDOW_MINUTES))
            ->count();

        if ($failed >= self::MAX_FAILED_ATTEMPTS) {
            return response()->json([
                'message' => 'Terlalu banyak percobaan masuk. Coba lagi dalam '.self::ATTEMPT_WINDOW_MINUTES.' menit.',
            ], 429);
        }

        $user = DB::table('users')->where('email', $email)->first();
        $valid = $user !== null && Hash::check($password, $user->password_hash);

        DB::table('login_attempts')->insert([
            'identifier' => $email,
            'succeeded' => $valid,
            'attempted_at' => now(),
        ]);

        if (! $valid) {
            return response()->json([
                'message' => 'Email atau password salah.',
                'errors' => ['email' => ['Email atau password salah.']],
            ], 401);
        }

        $this->writeAudit($user->id, 'auth.login');

        return $this->sessionResponse($user);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        $lastLogin = DB::table('audit_logs')
            ->where('user_id', $user->id)
            ->where('action', 'auth.login')
            ->orderByDesc('created_at')
            ->value('created_at');

        return response()->json([
            'data' => [
                'user' => self::publicUser($user),
                'lastLoginAt' => $lastLogin ? \Illuminate\Support\Carbon::parse($lastLogin)->toIso8601String() : null,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('sessionToken') ?: $request->cookie(AuthenticateSessionToken::COOKIE);

        if ($token) {
            DB::table('sessions')->where('token', $token)->delete();
        }

        return response()->json(['data' => ['ok' => true]])->withoutCookie(AuthenticateSessionToken::COOKIE);
    }

    /**
     * Issues a session token and returns it both as an httpOnly cookie and in the
     * body, so cookie-based and Bearer-based clients both work.
     */
    private function sessionResponse(object $user, int $status = 200): JsonResponse
    {
        $token = bin2hex(random_bytes(32));

        DB::table('sessions')->insert([
            'token' => $token,
            'user_id' => $user->id,
            'created_at' => now(),
            'expires_at' => now()->addDays(self::SESSION_DAYS),
        ]);

        $cookie = cookie(
            name: AuthenticateSessionToken::COOKIE,
            value: $token,
            minutes: self::SESSION_DAYS * 24 * 60,
            path: '/',
            secure: env('COOKIE_SECURE') === '1',
            httpOnly: true,
            sameSite: 'lax',
        );

        return response()
            ->json(['data' => ['user' => self::publicUser($user), 'token' => $token]], $status)
            ->withCookie($cookie);
    }

    private function writeAudit(int $userId, string $action, array $detail = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'detail' => json_encode($detail),
            'created_at' => now(),
        ]);
    }

    public static function publicUser(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'email' => $row->email,
            'phone' => $row->phone,
            'role' => $row->role,
            'accountType' => $row->account_type ?? 'reseller',
            'balance' => (int) $row->balance,
        ];
    }
}
