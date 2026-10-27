<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Membuat dan memeriksa kode OTP. Kode dikirim ke WhatsApp lewat Fonnte dan
 * hanya hash-nya yang disimpan.
 *
 * Dua tujuan: verifikasi akun baru (`account_verification`) dan konfirmasi
 * perubahan nomor WhatsApp/email (`contact_change`).
 */
class OtpService
{
    public const PURPOSE_ACCOUNT = 'account_verification';

    public const PURPOSE_CONTACT = 'contact_change';

    /** Kode berlaku 5 menit. */
    public const TTL_SECONDS = 300;

    /** Jeda minimal antar permintaan kode (detik). */
    public const RESEND_SECONDS = 60;

    /** Percobaan salah maksimal per kode sebelum harus minta kode baru. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private FonnteClient $fonnte) {}

    /**
     * Terbitkan kode baru dan kirim ke WhatsApp `$phone` (default: nomor akun).
     * `$payload` disimpan bersama kode dan dipakai saat verifikasi berhasil.
     *
     * @param  array<string,mixed>  $payload
     * @return array{channel:string,phoneMasked:string,expiresInSeconds:int,resendAfterSeconds:int,status:string,devCode:string|null}
     */
    public function issue(
        object $user,
        ?string $phone = null,
        string $purpose = self::PURPOSE_ACCOUNT,
        array $payload = [],
    ): array {
        $phone = $this->formatPhone($phone ?? (string) $user->phone);

        $last = DB::table('otp_codes')
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        // Batasi kirim ulang agar Fonnte tidak dibanjiri permintaan. Fallback
        // sandbox (token belum diisi) dikecualikan supaya alur tetap bisa dicoba.
        if ($this->fonnte->isConfigured() && $last !== null) {
            $wait = Carbon::parse($last->created_at)->addSeconds(self::RESEND_SECONDS)->getTimestamp() - now()->getTimestamp();

            if ($wait > 0) {
                return $this->meta($phone, self::TTL_SECONDS, $wait, 'cooldown', null);
            }
        }

        $code = (string) random_int(100000, 999999);

        DB::table('otp_codes')
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        DB::table('otp_codes')->insert([
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'purpose' => $purpose,
            'phone' => $phone,
            'code_hash' => hash('sha256', $code),
            'payload' => $payload === [] ? null : json_encode($payload),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'created_at' => now(),
        ]);

        $sent = $this->fonnte->send($phone, $this->message($code, $purpose));

        // Bila pengiriman gagal di lingkungan preview, kode dikembalikan agar
        // alur verifikasi tetap bisa diuji. Tidak pernah aktif di luar preview.
        $devCode = (! $sent && $this->previewFallback()) ? $code : null;

        return $this->meta(
            $phone,
            self::TTL_SECONDS,
            self::RESEND_SECONDS,
            $sent ? 'sent' : 'failed',
            $devCode,
        );
    }

    /**
     * Periksa kode. Bila benar, verifikasi akun ditandai atau perubahan kontak
     * (`payload`) langsung diterapkan ke pengguna.
     *
     * @return array{ok:bool,message?:string,payload?:array<string,mixed>}
     */
    public function verify(object $user, string $code, string $purpose = self::PURPOSE_ACCOUNT): array
    {
        $row = DB::table('otp_codes')
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return ['ok' => false, 'message' => 'Kode OTP belum diminta. Kirim ulang kode baru.'];
        }

        if (now()->greaterThan(Carbon::parse($row->expires_at))) {
            return ['ok' => false, 'message' => 'Kode OTP sudah kedaluwarsa. Kirim ulang kode baru.'];
        }

        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'message' => 'Terlalu banyak percobaan. Kirim ulang kode baru.'];
        }

        if (! hash_equals($row->code_hash, hash('sha256', $code))) {
            DB::table('otp_codes')->where('id', $row->id)->increment('attempts');

            return ['ok' => false, 'message' => 'Kode OTP salah.'];
        }

        DB::table('otp_codes')->where('id', $row->id)->update(['consumed_at' => now()]);

        $payload = $row->payload ? (array) json_decode((string) $row->payload, true) : [];

        if ($purpose === self::PURPOSE_CONTACT && $payload !== []) {
            DB::table('users')->where('id', $user->id)->update([
                'email' => $payload['email'] ?? $user->email,
                'phone' => $payload['phone'] ?? $user->phone,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]);

            return ['ok' => true, 'payload' => $payload];
        }

        DB::table('users')->where('id', $user->id)->update([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'phone_verified_at' => $user->phone_verified_at ?? now(),
        ]);

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * 08xx / +62xx / 8xx → 62xxxx (format yang dipakai Fonnte).
     */
    public function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 8) {
            return $phone;
        }

        return substr($phone, 0, 4).str_repeat('*', strlen($phone) - 7).substr($phone, -3);
    }

    private function message(string $code, string $purpose): string
    {
        $prefix = $purpose === self::PURPOSE_CONTACT
            ? 'Kode verifikasi perubahan kontak akun SE SMART PULSA'
            : 'Kode OTP SE SMART PULSA';

        return "{$prefix}: {$code}\n"
            .'Berlaku 5 menit. Jangan bagikan kode ini kepada siapa pun, termasuk yang mengaku admin.';
    }

    private function previewFallback(): bool
    {
        return (string) env('BASE44_PREVIEW_MODE') === '1';
    }

    /**
     * @return array{channel:string,phoneMasked:string,expiresInSeconds:int,resendAfterSeconds:int,status:string,devCode:string|null}
     */
    private function meta(string $phone, int $expiresIn, int $resendAfter, string $status, ?string $devCode): array
    {
        return [
            'channel' => 'whatsapp',
            'phoneMasked' => $this->maskPhone($phone),
            'expiresInSeconds' => max(0, $expiresIn),
            'resendAfterSeconds' => max(0, $resendAfter),
            'status' => $status,
            'devCode' => $devCode,
        ];
    }
}
