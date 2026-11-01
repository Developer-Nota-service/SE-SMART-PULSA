<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Membuat dan memeriksa kode OTP. Kode asli tidak pernah disimpan.
 */
class OtpService
{
    public const PURPOSE_ACCOUNT = 'account_verification';
    public const PURPOSE_EMAIL_CHANGE = 'email_change';
    public const PURPOSE_PHONE_CHANGE = 'phone_change';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';
    public const TTL_SECONDS = 300;
    public const RESEND_SECONDS = 60;
    public const MAX_ATTEMPTS = 5;

    public function __construct(private FonnteClient $fonnte) {}

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

        if ($this->canDeliver($channel) && $last !== null) {
            $wait = Carbon::parse($last->created_at)->addSeconds(self::RESEND_SECONDS)->getTimestamp() - now()->getTimestamp();

            if ($wait > 0) {
                return $this->meta($channel, $target, self::TTL_SECONDS, $wait, 'cooldown', null);
            }
        }

        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($user, $channel, $purpose, $target, $payload, $code): void {
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
                'code_hash' => $this->hashCode($code),
                'payload' => $payload === [] ? null : json_encode($payload),
                'attempts' => 0,
                'expires_at' => now()->addSeconds(self::TTL_SECONDS),
                'created_at' => now(),
            ]);
        });

        $sent = $channel === 'email'
            ? $this->sendEmail($target, $code, $purpose)
            : $this->fonnte->send($target, $this->message($code, $purpose));

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

    public function verify(object $user, string $code, string $purpose = self::PURPOSE_ACCOUNT): array
    {
        return DB::transaction(function () use ($user, $code, $purpose): array {
            $row = DB::table('otp_codes')
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
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

            if (! hash_equals($row->code_hash, $this->hashCode($code))) {
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
        }, 3);
    }

    private function hashCode(string $code): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY wajib diatur untuk membuat atau memverifikasi OTP.');
        }

        return hash_hmac('sha256', $code, $key);
    }

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
