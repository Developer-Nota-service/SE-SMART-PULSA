<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSessionToken;
use App\Services\OtpService;
use App\Support\LoginContext;
use App\Support\OtpMessage;
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

    /** Masa berlaku sesi "lupa sandi" setelah kode WhatsApp benar. */
    private const RESET_MINUTES = 15;

    public function __construct(private OtpService $otp) {}

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

        $existing = DB::table('users')->where('email', $input['email'])->first();

        if ($existing !== null && $existing->email_verified_at !== null) {
            return response()->json([
                'message' => 'Email sudah terdaftar.',
                'errors' => ['email' => ['Email sudah terdaftar.']],
            ], 422);
        }

        // Satu nomor WhatsApp hanya boleh menjadi bukti verifikasi satu akun.
        $phoneTaken = DB::table('users')
            ->where('phone', $input['phone'])
            ->where('email', '!=', $input['email'])
            ->whereNotNull('phone_verified_at')
            ->exists();

        if ($phoneTaken) {
            return response()->json([
                'message' => 'Data pendaftaran belum lengkap.',
                'errors' => ['phone' => ['Nomor HP sudah dipakai akun lain.']],
            ], 422);
        }

        if ($existing !== null) {
            // Pendaftaran sebelumnya belum terverifikasi: perbarui datanya lalu
            // kirim kode baru, jangan buat akun kedua.
            DB::table('users')->where('id', $existing->id)->update([
                'name' => $input['name'],
                'phone' => $input['phone'],
                'password_hash' => Hash::make($input['password']),
                'account_type' => $input['accountType'],
            ]);

            $id = (int) $existing->id;
            $this->writeAudit($id, 'auth.register.retry');
        } else {
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

            $this->writeAudit($id, 'auth.register');
        }

        $user = DB::table('users')->find($id);
        $verification = $this->otp->issue($user);

        return response()->json([
            'message' => OtpMessage::for($verification),
            'data' => [
                'email' => $user->email,
                'verification' => $verification,
            ],
        ], $existing === null ? 201 : 200);
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

        if ($user->email_verified_at === null || $user->phone_verified_at === null) {
            $verification = $this->otp->issue($user);

            return response()->json([
                'message' => 'Akun belum terverifikasi. '.OtpMessage::for($verification),
                'errors' => ['verification' => ['Selesaikan verifikasi kode OTP WhatsApp untuk masuk.']],
                'data' => [
                    'needsVerification' => true,
                    'email' => $user->email,
                    'verification' => $verification,
                ],
            ], 403);
        }

        $this->writeAudit($user->id, 'auth.login', LoginContext::capture($request));

        return $this->sessionResponse($user);
    }

    /**
     * Menyelesaikan verifikasi akun dengan kode OTP WhatsApp, lalu memberi sesi.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $code = preg_replace('/\D/', '', (string) $request->input('code')) ?? '';

        $user = $email === '' ? null : DB::table('users')->where('email', $email)->first();

        if ($user === null) {
            return response()->json([
                'message' => 'Email belum terdaftar.',
                'errors' => ['email' => ['Email belum terdaftar.']],
            ], 422);
        }

        if ($user->email_verified_at !== null && $user->phone_verified_at !== null) {
            return response()->json([
                'message' => 'Akun sudah terverifikasi. Silakan masuk dengan password.',
                'errors' => ['code' => ['Akun sudah terverifikasi.']],
            ], 422);
        }

        if (strlen($code) !== 6) {
            return response()->json([
                'message' => 'Kode OTP terdiri dari 6 angka.',
                'errors' => ['code' => ['Kode OTP terdiri dari 6 angka.']],
            ], 422);
        }

        $result = $this->otp->verify($user, $code);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'],
                'errors' => ['code' => [$result['message']]],
            ], 422);
        }

        $this->writeAudit((int) $user->id, 'auth.verified');

        return $this->sessionResponse(DB::table('users')->find($user->id));
    }

    /**
     * Kirim ulang kode OTP ke WhatsApp nomor pengguna.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $user = $email === '' ? null : DB::table('users')->where('email', $email)->first();

        if ($user === null) {
            return response()->json([
                'message' => 'Email belum terdaftar.',
                'errors' => ['email' => ['Email belum terdaftar.']],
            ], 422);
        }

        if ($user->email_verified_at !== null && $user->phone_verified_at !== null) {
            return response()->json([
                'message' => 'Akun sudah terverifikasi. Silakan masuk dengan password.',
                'errors' => ['email' => ['Akun sudah terverifikasi.']],
            ], 422);
        }

        $verification = $this->otp->issue($user);

        return response()->json([
            'message' => OtpMessage::for($verification),
            'data' => ['verification' => $verification],
        ]);
    }

    /**
     * Lupa sandi — langkah 1: kirim kode OTP WhatsApp ke nomor yang terdaftar.
     * Nomor yang tidak terdaftar dijawab dengan pesan generik agar tidak bisa
     * dipakai menebak nomor mana yang punya akun.
     */
    public function requestPasswordReset(Request $request): JsonResponse
    {
        $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

        if (! preg_match('/^0\d{8,14}$/', $phone)) {
            return response()->json([
                'message' => 'Nomor WhatsApp diawali 0 dan 9-15 digit.',
                'errors' => ['phone' => ['Nomor WhatsApp diawali 0 dan 9-15 digit.']],
            ], 422);
        }

        $user = DB::table('users')->where('phone', $phone)->orderBy('id')->first();

        if ($user === null) {
            return response()->json([
                'message' => 'Jika nomor ini terdaftar, kode verifikasi WhatsApp akan dikirim ke nomor tersebut.',
                'data' => ['verification' => null],
            ]);
        }

        $verification = $this->otp->issue($user, $phone, OtpService::PURPOSE_PASSWORD_RESET);

        return response()->json([
            'message' => OtpMessage::for($verification),
            'data' => ['verification' => $verification],
        ]);
    }

    /**
     * Lupa sandi — langkah 2: kode WhatsApp benar → email akun ditampilkan
     * (untuk pengguna yang lupa emailnya) dan sesi reset 15 menit dibuka.
     */
    public function verifyPasswordReset(Request $request): JsonResponse
    {
        $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';
        $code = preg_replace('/\D/', '', (string) $request->input('code')) ?? '';

        $user = $phone === '' ? null : DB::table('users')->where('phone', $phone)->orderBy('id')->first();

        if ($user === null) {
            return response()->json([
                'message' => 'Nomor atau kode verifikasi tidak cocok.',
                'errors' => ['code' => ['Nomor atau kode verifikasi tidak cocok.']],
            ], 422);
        }

        if (strlen($code) !== 6) {
            return response()->json([
                'message' => 'Kode OTP terdiri dari 6 angka.',
                'errors' => ['code' => ['Kode OTP terdiri dari 6 angka.']],
            ], 422);
        }

        $result = $this->otp->verify($user, $code, OtpService::PURPOSE_PASSWORD_RESET);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'],
                'errors' => ['code' => [$result['message']]],
            ], 422);
        }

        $token = bin2hex(random_bytes(32));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::RESET_MINUTES),
                'created_at' => now(),
            ],
        );

        $this->writeAudit((int) $user->id, 'auth.password_reset.verified');

        return response()->json([
            'message' => 'Nomor WhatsApp terverifikasi. Atur sandi baru untuk akun ini.',
            'data' => [
                'email' => $user->email,
                'resetToken' => $token,
                'expiresInSeconds' => self::RESET_MINUTES * 60,
            ],
        ]);
    }

    /**
     * Lupa sandi — langkah 3: simpan sandi baru dari sesi reset, putuskan sesi
     * lama, lalu beri sesi masuk. Hanya akun yang nomornya sudah terverifikasi
     * lewat OTP yang boleh lewat jalur ini.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('resetToken'));
        $password = (string) $request->input('password');

        if (strlen($password) < 8) {
            return response()->json([
                'message' => 'Password minimal 8 karakter.',
                'errors' => ['password' => ['Password minimal 8 karakter.']],
            ], 422);
        }

        $row = $token === '' ? null : DB::table('password_reset_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($row === null || now()->greaterThan(\Illuminate\Support\Carbon::parse($row->expires_at))) {
            return response()->json([
                'message' => 'Sesi atur ulang sandi sudah kedaluwarsa. Minta kode baru.',
                'errors' => ['resetToken' => ['Sesi atur ulang sandi sudah kedaluwarsa.']],
            ], 422);
        }

        $user = DB::table('users')->find($row->user_id);

        if ($user === null || $user->phone_verified_at === null) {
            return response()->json([
                'message' => 'Akun belum terverifikasi. Ulangi permintaan kode WhatsApp.',
                'errors' => ['resetToken' => ['Akun belum terverifikasi.']],
            ], 422);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password_hash' => Hash::make($password),
        ]);

        DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->writeAudit((int) $user->id, 'auth.password_reset');

        return $this->sessionResponse(DB::table('users')->find($user->id));
    }

    /**
     * @param  array{channel:string,targetMasked:string,status:string,resendAfterSeconds:int,devCode:string|null}  $verification
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        $lastLogin = DB::table('audit_logs')
            ->where('user_id', $user->id)
            ->where('action', 'auth.login')
            ->orderByDesc('created_at')
            ->first(['created_at', 'detail']);

        $detail = $lastLogin && is_string($lastLogin->detail)
            ? json_decode($lastLogin->detail, true)
            : [];
        $detail = is_array($detail) ? $detail : [];
        $at = $lastLogin ? \Illuminate\Support\Carbon::parse($lastLogin->created_at)->toIso8601String() : null;

        return response()->json([
            'data' => [
                'user' => self::publicUser($user),
                'lastLoginAt' => $at,
                'lastLogin' => $at === null ? null : [
                    'at' => $at,
                    'ip' => $detail['ip'] ?? null,
                    'location' => $detail['location'] ?? null,
                    'device' => $detail['device'] ?? null,
                ],
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
            'emailVerified' => ($row->email_verified_at ?? null) !== null,
            'phoneVerified' => ($row->phone_verified_at ?? null) !== null,
            'avatarUrl' => ($row->avatar_path ?? null)
                ? '/api/v1/avatar/'.basename((string) $row->avatar_path)
                : null,
        ];
    }
}
