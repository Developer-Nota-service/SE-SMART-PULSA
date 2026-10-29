<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim pesan WhatsApp lewat gateway Fonnte (https://fonnte.com).
 * Dipakai untuk mengirim kode OTP verifikasi akun.
 */
class FonnteClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('fonnte.token')) !== '';
    }

    /**
     * Kirim satu pesan WhatsApp. Nomor harus sudah dalam format internasional
     * (62...). Mengembalikan false bila token kosong atau Fonnte menolak.
     */
    public function send(string $phone, string $message): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('OTP tidak terkirim: FONNTE_TOKEN belum diisi.');

            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => (string) config('fonnte.token')])
                ->asForm()
                ->timeout(15)
                ->post((string) config('fonnte.url'), [
                    'target' => $phone,
                    'message' => $message,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Gagal menghubungi Fonnte: '.$exception->getMessage());

            return false;
        }

        if ($response->failed()) {
            Log::warning('Fonnte menolak permintaan OTP.', ['http_status' => $response->status()]);

            return false;
        }

        if ($response->json('status') !== true) {
            Log::warning('Fonnte gagal mengirim OTP.', ['reason' => (string) $response->json('reason')]);

            return false;
        }

        return true;
    }
}
