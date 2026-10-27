<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Membuat dan memeriksa kode OTP verifikasi akun. Kode dikirim ke WhatsApp
 * nomor pengguna lewat Fonnte dan hanya hash-nya yang disimpan.
 */
class OtpService
{
    /** Kode berlaku 5 menit. */
    public const TTL_SECONDS = 300;

    /** Jeda minimal antar permintaan kode (detik). */
    public const RESEND_SECONDS = 60;

    /** Percobaan salah maksimal per kode sebelum harus minta kode baru. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private FonnteClient $fonnte) {}

    /**
     * Terbitkan kode baru untuk pengguna dan kirim via WhatsApp.
     *
     * @return array{channel:string,phoneMasked:string,expiresInSeconds:int,resendAfterSeconds:int,status:string,devCode:string|null}
     */
    public function issue(object $user): array
    {
        $phone = $this->formatPhone((string) $user->phone);
        $last = DB::table('otp_codes')
            ->where('user_id', $user->id)
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
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        DB::table('otp_codes')->insert([
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'phone' => $phone,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'created_at' => now(),
        ]);

        $sent = $this->fonnte->send($phone, $this->message($code));

        // Tanpa token Fonnte di lingkungan preview, kode ditampilkan agar alur
        // verifikasi tetap bisa diuji. Tidak pernah aktif di luar preview.
        $devCode = (! $sent && $this->previewFallback()) ? $code : null;

        return $this->meta(
            $phone,
            self::TTL_SECONDS,
            self::RESEND_SECONDS,
            $sent ? 'sent' : 'not_configured',
            $devCode,
        );
    }

    /**
     * @return array{ok:bool,message?:string}
     */
    public function verify(object $user, string $code): array
    {
        $row = DB::table('otp_codes')
            ->where('user_id', $user->id)
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
        DB::table('users')->where('id', $user->id)->update([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'phone_verified_at' => $user->phone_verified_at ?? now(),
        ]);

        return ['ok' => true];
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

    private function message(string $code): string
    {
        return "Kode OTP SE SMART PULSA Anda: {$code}\n"
            ."Berlaku 5 menit. Jangan bagikan kode ini kepada siapa pun, termasuk yang mengaku admin.";
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
