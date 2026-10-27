<?php

namespace App\Support;

class OtpMessage
{
    /**
     * Pesan manusiawi untuk status pengiriman kode OTP.
     *
     * @param  array{channel?:string,targetMasked?:string,status?:string,resendAfterSeconds?:int,devCode?:string|null}  $verification
     */
    public static function for(array $verification): string
    {
        $channel = ($verification['channel'] ?? 'whatsapp') === 'email' ? 'email' : 'WhatsApp';
        $target = (string) ($verification['targetMasked'] ?? '');

        if (($verification['devCode'] ?? null) !== null) {
            return "Mode sandbox (pengiriman {$channel} belum terhubung): kode OTP untuk {$target} adalah {$verification['devCode']}.";
        }

        return match ($verification['status'] ?? 'sent') {
            'cooldown' => 'Kode sebelumnya baru dikirim. Tunggu '.($verification['resendAfterSeconds'] ?? 0).' detik untuk minta kode baru.',
            'failed' => "Kode OTP gagal dikirim ke {$channel}. Periksa tujuan Anda lalu minta kode baru.",
            default => "Kode OTP dikirim ke {$channel} {$target}.",
        };
    }
}
