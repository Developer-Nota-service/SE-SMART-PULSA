<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OtpService;
use App\Support\OtpMessage;
use App\Support\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Pengaturan akun: nama, foto profil, kontak (email + WhatsApp) dengan
 * konfirmasi OTP, dan perpindahan tipe akun.
 */
class ProfileController extends Controller
{
    private const MAX_AVATAR_KB = 2048;

    public function __construct(private OtpService $otp) {}

    public function updateName(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name'));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            return response()->json([
                'message' => 'Nama harus 2-60 karakter.',
                'errors' => ['name' => ['Nama harus 2-60 karakter.']],
            ], 422);
        }

        $user = $request->attributes->get('authUser');
        DB::table('users')->where('id', $user->id)->update(['name' => $name]);
        $this->writeAudit((int) $user->id, 'profile.name');

        return $this->userResponse((int) $user->id);
    }

    /**
     * Minta kode OTP untuk setiap kontak yang berubah: email baru menerima kode
     * lewat email, nomor baru lewat WhatsApp. Masing-masing baru diterapkan
     * setelah kode-nya dikonfirmasi.
     */
    public function changeContact(Request $request): JsonResponse
    {
        $input = [
            'email' => strtolower(trim((string) $request->input('email'))),
            'phone' => preg_replace('/\D/', '', (string) $request->input('phone')) ?? '',
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/'],
            'phone' => ['required', 'regex:/^0\d{8,14}$/'],
        ], [
            'email.required' => 'Format email tidak valid.',
            'email.regex' => 'Format email tidak valid.',
            'phone.required' => 'Nomor HP diawali 0 dan 9-15 digit.',
            'phone.regex' => 'Nomor HP diawali 0 dan 9-15 digit.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Data kontak belum lengkap.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $user = $request->attributes->get('authUser');
        $emailChanged = $input['email'] !== strtolower((string) $user->email);
        $phoneChanged = $input['phone'] !== (string) $user->phone;

        if (! $emailChanged && ! $phoneChanged) {
            return response()->json([
                'message' => 'Email dan nomor WhatsApp tidak berubah.',
                'errors' => ['phone' => ['Email dan nomor WhatsApp tidak berubah.']],
            ], 422);
        }

        if ($emailChanged && DB::table('users')->where('email', $input['email'])->where('id', '!=', $user->id)->exists()) {
            return response()->json([
                'message' => 'Data kontak belum lengkap.',
                'errors' => ['email' => ['Email sudah dipakai akun lain.']],
            ], 422);
        }

        if ($phoneChanged) {
            $phoneTaken = DB::table('users')
                ->where('phone', $input['phone'])
                ->where('id', '!=', $user->id)
                ->whereNotNull('phone_verified_at')
                ->exists();

            if ($phoneTaken) {
                return response()->json([
                    'message' => 'Data kontak belum lengkap.',
                    'errors' => ['phone' => ['Nomor HP sudah dipakai akun lain.']],
                ], 422);
            }
        }

        // Perubahan baru diterapkan setelah kode untuk masing-masing kontak
        // terbukti benar: email lewat kode ke email baru, nomor lewat WhatsApp baru.
        $pending = [];

        if ($emailChanged) {
            $pending['email'] = $this->otp->issue(
                $user,
                $input['email'],
                OtpService::PURPOSE_EMAIL_CHANGE,
                ['email' => $input['email']],
            );
        }

        if ($phoneChanged) {
            $pending['phone'] = $this->otp->issue(
                $user,
                $input['phone'],
                OtpService::PURPOSE_PHONE_CHANGE,
                ['phone' => $input['phone']],
            );
        }

        return response()->json([
            'message' => implode(' ', array_map(fn (array $verification) => OtpMessage::for($verification), $pending)),
            'data' => ['pending' => $pending],
        ]);
    }

    public function verifyContact(Request $request): JsonResponse
    {
        $channel = (string) $request->input('channel');
        $code = preg_replace('/\D/', '', (string) $request->input('code')) ?? '';

        if (! in_array($channel, ['email', 'phone'], true)) {
            return response()->json([
                'message' => 'Pilih kontak yang ingin diverifikasi.',
                'errors' => ['channel' => ['Pilih kontak yang ingin diverifikasi.']],
            ], 422);
        }

        if (strlen($code) !== 6) {
            return response()->json([
                'message' => 'Kode OTP terdiri dari 6 angka.',
                'errors' => ['code' => ['Kode OTP terdiri dari 6 angka.']],
            ], 422);
        }

        $user = $request->attributes->get('authUser');
        $purpose = $channel === 'email' ? OtpService::PURPOSE_EMAIL_CHANGE : OtpService::PURPOSE_PHONE_CHANGE;
        $result = $this->otp->verify($user, $code, $purpose);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'],
                'errors' => ['code' => [$result['message']]],
            ], 422);
        }

        $this->writeAudit((int) $user->id, 'profile.'.$channel, $result['payload']);

        return $this->userResponse(
            (int) $user->id,
            200,
            ($channel === 'email' ? 'Email' : 'Nomor WhatsApp').' baru tersimpan dan terverifikasi.',
        );
    }

    /**
     * Pindah tipe akun tanpa perlu mendaftar ulang. Harga, metode bayar, dan
     * seluruh perilaku akun mengikuti tipe yang baru.
     */
    public function changeAccountType(Request $request): JsonResponse
    {
        $type = (string) $request->input('accountType');

        if (! in_array($type, Pricing::ACCOUNT_TYPES, true)) {
            return response()->json([
                'message' => 'Tipe akun tidak dikenal.',
                'errors' => ['accountType' => ['Pilih tipe akun reseller atau pribadi.']],
            ], 422);
        }

        $user = $request->attributes->get('authUser');
        $fresh = DB::table('users')->find($user->id);
        $current = (string) ($fresh->account_type ?? 'reseller');

        if ($current === $type) {
            return response()->json([
                'message' => 'Akun ini sudah bertipe '.($type === 'personal' ? 'pribadi' : 'reseller').'.',
                'errors' => ['accountType' => ['Tipe akun tidak berubah.']],
            ], 422);
        }

        // Saldo reseller tidak bisa dipakai akun pribadi, jadi jangan sampai
        // uang pengguna tertahan setelah berpindah tipe.
        if ($type === 'personal') {
            $balance = (int) $fresh->balance;

            if ($balance > 0) {
                return response()->json([
                    'message' => 'Saldo Rp'.number_format($balance, 0, ',', '.').' masih tersisa. Habiskan saldo dulu sebelum pindah ke akun pribadi.',
                    'errors' => ['accountType' => ['Saldo masih tersisa.']],
                ], 422);
            }

            $pendingDeposit = DB::table('deposits')
                ->where('user_id', $fresh->id)
                ->where('status', 'pending')
                ->exists();

            if ($pendingDeposit) {
                return response()->json([
                    'message' => 'Masih ada deposit yang menunggu pembayaran. Selesaikan atau biarkan kedaluwarsa dulu.',
                    'errors' => ['accountType' => ['Deposit masih pending.']],
                ], 422);
            }
        }

        DB::table('users')->where('id', $fresh->id)->update(['account_type' => $type]);
        $this->writeAudit((int) $fresh->id, 'profile.account_type', ['from' => $current, 'to' => $type]);

        return $this->userResponse(
            (int) $fresh->id,
            200,
            'Tipe akun diganti ke '.($type === 'personal' ? 'pribadi' : 'reseller').'.',
        );
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $file = $request->file('avatar');

        if ($file === null) {
            return response()->json([
                'message' => 'Pilih foto profil terlebih dahulu.',
                'errors' => ['avatar' => ['Pilih foto profil terlebih dahulu.']],
            ], 422);
        }

        $validator = Validator::make(['avatar' => $file], [
            'avatar' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_AVATAR_KB],
        ], [
            'avatar.image' => 'Foto harus berupa gambar.',
            'avatar.mimes' => 'Format foto harus JPG, PNG, atau WEBP.',
            'avatar.max' => 'Ukuran foto maksimal 2 MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Foto profil tidak valid.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $user = $request->attributes->get('authUser');
        $old = DB::table('users')->where('id', $user->id)->value('avatar_path');

        $name = bin2hex(random_bytes(16)).'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $path = $file->storeAs('avatars', $name, 'local');

        DB::table('users')->where('id', $user->id)->update(['avatar_path' => $path]);

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        $this->writeAudit((int) $user->id, 'profile.avatar');

        return $this->userResponse((int) $user->id);
    }

    /**
     * Menyajikan foto profil. Nama file acak (32 hex) sehingga URL tidak bisa
     * ditebak; dipakai langsung oleh <img> di aplikasi.
     */
    public function avatar(string $file): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $file = basename($file);

        abort_unless(preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|webp)$/', $file) === 1, 404);

        $path = 'avatars/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function userResponse(int $userId, int $status = 200, ?string $message = null): JsonResponse
    {
        $row = DB::table('users')->find($userId, [
            'id',
            'name',
            'email',
            'phone',
            'role',
            'account_type',
            'balance',
            'email_verified_at',
            'phone_verified_at',
            'avatar_path',
        ]);

        $payload = ['user' => AuthController::publicUser($row)];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json(['data' => $payload], $status);
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
}
