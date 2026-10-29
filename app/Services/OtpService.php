<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Membuat dan memeriksa kode OTP. Kode dikirim lewat WhatsApp (Fonnte) atau
 * email, dan hanya hash-nya yang disimpan.
 *
 * Tiga tujuan: verifikasi akun baru (`account_verification`), konfirmasi email
 * baru (`email_change`, kode dikirim ke email baru), dan konfirmasi nomor
 * WhatsApp baru (`phone_change`, kode dikirim ke nomor baru).
 */
class OtpService
{
    public const PURPOSE_ACCOUNT = 'account_verification';

    public const PURPOSE_EMAIL_CHANGE = 'email_change';

    public const PURPOSE_PHONE_CHANGE = 'phone_change';

    /** Lupa sandi: kode ke nomor WhatsApp terdaftar sebagai bukti kepemilikan akun. */
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    /** Kode berlaku 5 menit. */
    public const TTL_SECONDS = 300;

    /** Jeda minimal antar permintaan kode (detik). */
    public const RESEND_SECONDS = 60;

    /** Percobaan salah maksimal per kode sebelum harus minta kode baru. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private FonnteClient $fonnte) {}

    /**
     * Terbitkan kode baru dan kirim ke tujuan `$target` (default: email/nomor
     * akun). `$payload` disimpan bersama kode dan dipakai saat verifikasi berhasil.
     *
     * @param  array<string,mixed>  $payload
     * @return array{channel:string,targetMasked:string,expiresInSeconds:int,resendAfterSeconds:int,status:string,devCode:string|null}
     */
    public function issue(
        object $user,
        ?string $target = null,
        string $purpose = self::PURPOSE_ACCOUNT,
        array $payload = [],
    ): array {
        $channel = $this->channelFor($purpose);
        $target = $channel === 'email'
            ? strtolower(trim((string) ($target ?? $user->email)))
            : $this->formatPhone((string) ($target ?? $user->phone));

        $last = DB::table('otp_codes')
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        // Batasi kirim ulang agar pengiriman tidak dibanjiri permintaan. Fallback
        // sandbox (kanal belum terhubung) dikecualikan supaya alur tetap bisa dicoba.
        if ($this->canDeliver($channel) && $last !== null) {
            $wait = Carbon::parse($last->created_at)->addSeconds(self::RESEND_SECONDS)->getTimestamp() - now()->getTimestamp();

            if ($wait > 0) {
                return $this->meta($channel, $target, self::TTL_SECONDS, $wait, 'cooldown', null);
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
            'channel' => $channel,
            'purpose' => $purpose,
            'target' => $target,
            'code_hash' => hash('sha256', $code),
            'payload' => $payload === [] ? null : json_encode($payload),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'created_at' => now(),
        ]);

        $sent = $channel === 'email'
            ? $this->sendEmail($target, $code, $purpose)
            : $this->fonnte->send($target, $this->message($code, $purpose));

        // Bila pengiriman gagal di lingkungan preview, kode dikembalikan agar
        // alur verifikasi tetap bisa diuji. Tidak pernah aktif di luar preview.
        $devCode = (! $sent && $this->previewFallback()) ? $code : null;

        return $this->meta(
            $channel,
            $target,
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

        if ($purpose === self::PURPOSE_EMAIL_CHANGE && isset($payload['email'])) {
            DB::table('users')->where('id', $user->id)->update([
                'email' => $payload['email'],
                'email_verified_at' => now(),
            ]);

            return ['ok' => true, 'payload' => $payload];
        }

        if ($purpose === self::PURPOSE_PHONE_CHANGE && isset($payload['phone'])) {
            DB::table('users')->where('id', $user->id)->update([
                'phone' => $payload['phone'],
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

    private function channelFor(string $purpose): string
    {
        return $purpose === self::PURPOSE_EMAIL_CHANGE ? 'email' : 'whatsapp';
    }

    private function canDeliver(string $channel): bool
    {
        return $channel === 'email' ? $this->emailReady() : $this->fonnte->isConfigured();
    }

    /** Email hanya bisa dikirim bila mailer diarahkan ke transport nyata (bukan log/array). */
    private function emailReady(): bool
    {
        return ! in_array(strtolower((string) config('mail.default')), ['', 'log', 'array', 'null'], true);
    }

    private function sendEmail(string $email, string $code, string $purpose): bool
    {
        if (! $this->emailReady()) {
            Log::warning('OTP email tidak terkirim: MAIL_MAILER belum diarahkan ke SMTP.');

            return false;
        }

        try {
            Mail::raw($this->message($code, $purpose), function ($mail) use ($email) {
                $mail->to($email)->subject('Kode verifikasi email SE SMART PULSA');
            });
        } catch (\Throwable $exception) {
            Log::warning('Gagal mengirim OTP email: '.$exception->getMessage());

            return false;
        }

        return true;
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 8) {
            return $phone;
        }

        return substr($phone, 0, 4).str_repeat('*', strlen($phone) - 7).substr($phone, -3);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($domain === '') {
            return $email;
        }

        return mb_substr($local, 0, 2).str_repeat('*', 3).'@'.$domain;
    }

    private function message(string $code, string $purpose): string
    {
        $prefix = match ($purpose) {
            self::PURPOSE_EMAIL_CHANGE => 'Kode verifikasi email baru akun SE SMART PULSA',
            self::PURPOSE_PHONE_CHANGE => 'Kode verifikasi nomor WhatsApp baru akun SE SMART PULSA',
            self::PURPOSE_PASSWORD_RESET => 'Kode lupa sandi akun SE SMART PULSA',
            default => 'Kode OTP SE SMART PULSA',
        };

        return "{$prefix}: {$code}\n"
            .'Berlaku 5 menit. Jangan bagikan kode ini kepada siapa pun, termasuk yang mengaku admin.';
    }

    private function previewFallback(): bool
    {
        return (string) env('BASE44_PREVIEW_MODE') === '1';
    }

    /**
     * @return array{channel:string,targetMasked:string,expiresInSeconds:int,resendAfterSeconds:int,status:string,devCode:string|null}
     */
    private function meta(string $channel, string $target, int $expiresIn, int $resendAfter, string $status, ?string $devCode): array
    {
        return [
            'channel' => $channel,
            'targetMasked' => $channel === 'email' ? $this->maskEmail($target) : $this->maskPhone($target),
            'expiresInSeconds' => max(0, $expiresIn),
            'resendAfterSeconds' => max(0, $resendAfter),
            'status' => $status,
            'devCode' => $devCode,
        ];
    }
}
