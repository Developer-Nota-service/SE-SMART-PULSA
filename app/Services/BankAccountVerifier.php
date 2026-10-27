<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengecekan nama pemilik rekening/e-wallet lewat penyedia pengecekan pihak
 * ketiga. Tujuannya mencegah salah ketik nomor atau nama tujuan penarikan.
 *
 * Kontrak penyedia (config `services.bank_inquiry`): POST JSON ke
 * `BANK_INQUIRY_URL` dengan header `Authorization: Bearer <BANK_INQUIRY_KEY>`
 * dan body `{ channel_type, provider_code, account_number }`. Respons dianggap
 * berhasil bila memuat nama pemilik rekening (`account_holder` / `name`, juga
 * di dalam `data`) dan status sukses (`SUCCESS`/`VERIFIED`/`OK`). Format
 * respons Flip (`{"status":"SUCCESS","account_holder":"…"}`) langsung dikenali.
 *
 * Bila penyedia belum dikonfigurasi, hasilnya `configured: false` — pengguna
 * tetap bisa mengajukan penarikan dengan nama yang diketik sendiri, dan
 * catatannya ditandai belum terverifikasi untuk diperiksa manual.
 */
class BankAccountVerifier
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.bank_inquiry.url')) !== '';
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    public function verify(string $channelType, string $providerCode, string $accountNumber): array
    {
        if (! $this->isConfigured()) {
            return $this->result(false, 'unavailable', null, 'Pengecekan nama rekening otomatis belum aktif.');
        }

        $cacheKey = "bank-inquiry:{$channelType}:{$providerCode}:{$accountNumber}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($channelType, $providerCode, $accountNumber) {
            return $this->call($channelType, $providerCode, $accountNumber);
        });
    }

    /**
     * Bandingkan nama yang diketik pengguna dengan nama hasil pengecekan.
     * Perbedaan kapitalisasi, titik, dan gelar tambahan tidak dianggap salah.
     */
    public static function namesMatch(string $typed, string $verified): bool
    {
        $a = self::tokens($typed);
        $b = self::tokens($verified);

        if ($a === [] || $b === []) {
            return false;
        }

        // Semua kata dari nama yang lebih pendek harus muncul di nama lainnya.
        [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];

        return array_diff($short, $long) === [];
    }

    /** @return array<int, string> */
    private static function tokens(string $value): array
    {
        $clean = preg_replace('/[^A-Za-z ]+/', ' ', strtoupper($value)) ?? '';
        $tokens = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', $clean) ?? '')));

        // Buang kata sandang/gelar umum yang sering berbeda antar sumber data.
        return array_values(array_diff($tokens, ['PT', 'CV', 'TBK', 'H', 'HJ']));
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    private function call(string $channelType, string $providerCode, string $accountNumber): array
    {
        try {
            $response = Http::withToken((string) config('services.bank_inquiry.key'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.bank_inquiry.timeout', 15))
                ->post((string) config('services.bank_inquiry.url'), [
                    'channel_type' => $channelType,
                    'provider_code' => $providerCode,
                    'account_number' => $accountNumber,
                ]);
        } catch (\Throwable $error) {
            Log::warning('Cek nama rekening gagal', ['provider' => $providerCode, 'error' => $error->getMessage()]);

            return $this->result(false, 'unavailable', null, 'Pengecekan nama rekening sedang tidak tersedia. Coba lagi.');
        }

        $payload = $response->json() ?? [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $status = strtoupper((string) ($payload['status'] ?? $data['status'] ?? ''));
        $holder = $payload['account_holder'] ?? $payload['accountHolder'] ?? $data['account_holder']
            ?? $data['accountHolder'] ?? $data['name'] ?? $payload['name'] ?? null;
        $holder = is_string($holder) ? trim($holder) : null;
        $holder = $holder === '' ? null : $holder;

        $ok = $response->successful()
            && $holder !== null
            && ($status === '' || in_array($status, ['SUCCESS', 'VERIFIED', 'OK'], true))
            && ($payload['success'] ?? $data['success'] ?? true) !== false;

        if ($ok) {
            return $this->result(true, 'verified', $holder, null);
        }

        if (! $response->successful()) {
            Log::warning('Cek nama rekening ditolak penyedia', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);
        }

        $message = match ($status) {
            'INVALID_ACCOUNT_NUMBER' => 'Nomor rekening tidak ditemukan di bank/e-wallet tujuan.',
            'BLACK_LISTED', 'SUSPECTED_ACCOUNT' => 'Rekening tujuan diblokir penyedia pengecekan.',
            'CLOSED' => 'Rekening tujuan sudah ditutup.',
            'TOP_UP_LIMIT_EXCEEDED' => 'Rekening e-wallet tujuan sudah mencapai batas top up.',
            default => 'Nama pemilik rekening tidak dapat diverifikasi. Periksa kembali nomor rekening Anda.',
        };

        return $this->result(false, $status === '' ? 'unknown' : strtolower($status), $holder, $message);
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    private function result(bool $verified, string $status, ?string $accountHolder, ?string $message): array
    {
        return [
            'configured' => $this->isConfigured(),
            'verified' => $verified,
            'status' => $status,
            'accountHolder' => $accountHolder,
            'message' => $message,
        ];
    }
}
