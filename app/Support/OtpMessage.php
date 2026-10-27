<?php

namespace App\Support;

class OtpMessage
{
    /**
     * Pesan manusiawi untuk status pengiriman kode OTP.
     *
     * @param  array{phoneMasked?:string,status?:string,resendAfterSeconds?:int,devCode?:string|null}  $verification
     */
    public static function for(array $verification): string
    {
        $phone = (string) ($verification['phoneMasked'] ?? '');

        if (($verification['devCode'] ?? null) !== null) {
            return "Mode sandbox (Fonnte belum terhubung): kode OTP untuk {$phone} adalah {$verification['devCode']}.";
        }

        return match ($verification['status'] ?? 'sent') {
            'cooldown' => 'Kode sebelumnya baru dikirim. Tunggu '.($verification['resendAfterSeconds'] ?? 0).' detik untuk minta kode baru.',
            'failed' => 'Kode OTP gagal dikirim ke WhatsApp. Periksa nomor Anda lalu minta kode baru.',
            default => "Kode OTP dikirim ke WhatsApp {$phone}.",
        };
    }
}
