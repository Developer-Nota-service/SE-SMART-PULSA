<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengecekan nama pemilik rekening tujuan penarikan lewat Midtrans Iris
 * (`GET /iris/api/v1/account_validation?bank=&account=`).
 *
 * Iris mengembalikan `account_name` bila rekening valid, atau `error_message` /
 * `errors` bila rekening ditolak. Hanya rekening bank yang didukung — e-wallet
 * tidak punya endpoint pengecekan di Iris, jadi jatuh ke input nama manual.
 *
 * Kunci Iris (`MIDTRANS_IRIS_KEY`) berbeda dari Server Key Snap dan dibuat di
 * dashboard Iris (perlu aktivasi disbursement). Tanpa kunci itu, hasilnya
 * `configured: false`: pengguna tetap bisa mengajukan penarikan dengan nama yang
 * diketik sendiri, dan catatannya ditandai belum terverifikasi untuk diperiksa
 * manual. Hal yang sama berlaku saat Iris menolak permintaan kita (401/403/5xx,
 * bukan JSON) — itu gangguan penyedia, bukan bukti nomor rekening salah.
 */
class BankAccountVerifier
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.midtrans.iris.key')) !== '';
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    public function verify(string $channelType, string $providerCode, string $accountNumber): array
    {
        if (! $this->isConfigured()) {
            return $this->unavailable('Pengecekan nama rekening otomatis belum aktif.');
        }

        if ($channelType !== 'bank') {
            return $this->unavailable('Cek nama otomatis Midtrans Iris hanya berlaku untuk rekening bank.');
        }

        $cacheKey = "iris-inquiry:{$providerCode}:{$accountNumber}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->call($providerCode, $accountNumber);

        // Jawaban pasti (rekening valid/ditolak) disimpan 10 menit; gangguan
        // penyedia hanya 1 menit supaya pengecekan cepat pulih.
        Cache::put($cacheKey, $result, $result['configured'] ? now()->addMinutes(10) : now()->addMinute());

        return $result;
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
    private function call(string $providerCode, string $accountNumber): array
    {
        $url = rtrim((string) config('services.midtrans.iris.base_url'), '/').'/api/v1/account_validation';

        try {
            $response = Http::withBasicAuth((string) config('services.midtrans.iris.key'), '')
                ->acceptJson()
                ->timeout((int) config('services.midtrans.iris.timeout', 15))
                ->get($url, ['bank' => $providerCode, 'account' => $accountNumber]);
        } catch (\Throwable $error) {
            Log::warning('Cek rekening Iris gagal', ['bank' => $providerCode, 'error' => $error->getMessage()]);

            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        $decoded = $response->json();
        $payload = is_array($decoded) ? $decoded : [];
        $holder = trim((string) ($payload['account_name'] ?? ''));

        if ($response->successful() && $holder !== '') {
            return $this->result(true, 'verified', $holder, null);
        }

        // Kunci belum benar, Iris belum aktif, atau layanan bermasalah: bukan
        // bukti nomor rekening salah, jadi jangan memblokir pengajuan.
        if (! $response->successful()) {
            Log::warning('Cek rekening Iris tidak tersedia', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        $errors = $payload['errors'] ?? null;
        $message = is_array($errors)
            ? trim((string) (collect($errors)->flatten()->first() ?? ''))
            : '';

        if ($message === '') {
            $message = trim((string) ($payload['error_message'] ?? ''));
        }

        if ($message === '') {
            $message = 'Nomor rekening tidak dapat diverifikasi. Periksa kembali nomor rekening Anda.';
        }

        return $this->result(false, 'rejected', null, $message);
    }

    /**
     * Penyedia belum/tidak bisa menjawab. Hasilnya `configured: false` supaya
     * pengajuan penarikan tetap bisa dikirim dengan nama yang diketik pengguna
     * (dicatat `unverified` untuk diperiksa manual).
     *
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    private function unavailable(string $message): array
    {
        return [
            'configured' => false,
            'verified' => false,
            'status' => 'unavailable',
            'accountHolder' => null,
            'message' => $message,
        ];
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
