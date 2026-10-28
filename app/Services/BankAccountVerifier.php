<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cek nama pemilik rekening bank & e-wallet tujuan penarikan lewat
 * **Xendit Data Services (Name Validator)**.
 *
 * Autentikasi HTTP Basic: secret key Xendit sebagai username, password kosong.
 * Produk ini harus diaktifkan Xendit lebih dulu (minta aktivasi ke
 * help@xendit.co); tanpa aktivasi semua permintaan dijawab 401/403 dan hasilnya
 * `configured: false` sehingga pengguna mengisi nama sendiri.
 *
 * - Bank: `POST /bank_account_data_requests` `{bank_account_number, bank_code}`
 *   → `is_found` + `result.account_holder_name`.
 * - E-wallet: `POST /ewallet_account_data_requests`
 *   `{ewallet_account_number, ewallet_type}` → `result.ewallet_account_name`.
 *
 * Bedakan dua jenis kegagalan:
 * - penyedia memastikan rekening tidak ada (`is_found: false`, atau
 *   `error_code` invalid/not found) → pengajuan diblokir;
 * - penyedia tidak bisa menjawab (kunci kosong/ditolak 401/403, 5xx, timeout,
 *   DNS gagal) → `configured: false`: pengguna mengisi nama sendiri dan catatan
 *   ditandai `unverified`. Satu kunci salah tidak boleh mematikan penarikan.
 */
class BankAccountVerifier
{
    private const BANK_PATH = '/bank_account_data_requests';

    private const EWALLET_PATH = '/ewallet_account_data_requests';

    /** Kode bank Xendit untuk daftar bank di App\Support\TransferChannels. */
    private const BANK_CODES = [
        'bca' => 'BCA',
        'bni' => 'BNI',
        'bri' => 'BRI',
        'mandiri' => 'MANDIRI',
        'permata' => 'PERMATA',
        'cimb' => 'CIMB',
        'danamon' => 'DANAMON',
        'btn' => 'BTN',
        'bsi' => 'BSI',
        'jago' => 'JAGO',
    ];

    /** Kode e-wallet Xendit untuk daftar e-wallet di App\Support\TransferChannels. */
    private const EWALLET_CODES = [
        'gopay' => 'GOPAY',
        'ovo' => 'OVO',
        'dana' => 'DANA',
        'shopeepay' => 'SHOPEEPAY',
        'linkaja' => 'LINKAJA',
    ];

    public function isConfigured(): bool
    {
        return $this->secretKey() !== '';
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    public function verify(string $channelType, string $providerCode, string $accountNumber): array
    {
        if (! $this->isConfigured()) {
            return $this->unavailable('Pengecekan nama rekening otomatis belum aktif.');
        }

        $cacheKey = "xendit:{$channelType}:{$providerCode}:{$accountNumber}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $channelType === 'ewallet'
            ? $this->checkEwallet($providerCode, $accountNumber)
            : $this->checkBank($providerCode, $accountNumber);

        // Jawaban pasti (nama ketemu/ditolak) disimpan 10 menit; gangguan 1 menit.
        $ttl = in_array($result['status'], ['verified', 'rejected'], true)
            ? now()->addMinutes(10)
            : now()->addMinute();

        Cache::put($cacheKey, $result, $ttl);

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
    private function checkBank(string $providerCode, string $accountNumber): array
    {
        $bankCode = self::BANK_CODES[$providerCode] ?? null;

        if ($bankCode === null) {
            return $this->unavailable('Pengecekan nama otomatis belum tersedia untuk bank ini. Masukkan nama sesuai buku tabungan.');
        }

        $response = $this->send(self::BANK_PATH, [
            'bank_account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        if ($response === null) {
            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        return $this->interpret(
            $response[0],
            $response[1],
            'account_holder_name',
            'Masukkan nama sesuai buku tabungan.',
        );
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    private function checkEwallet(string $providerCode, string $accountNumber): array
    {
        $ewalletType = self::EWALLET_CODES[$providerCode] ?? null;

        if ($ewalletType === null) {
            return $this->unavailable('Pengecekan nama otomatis belum tersedia untuk e-wallet ini. Masukkan nama sesuai aplikasi e-wallet.');
        }

        $response = $this->send(self::EWALLET_PATH, [
            'ewallet_account_number' => $accountNumber,
            'ewallet_type' => $ewalletType,
        ]);

        if ($response === null) {
            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        return $this->interpret(
            $response[0],
            $response[1],
            'ewallet_account_name',
            'Masukkan nama sesuai aplikasi e-wallet.',
        );
    }

    /**
     * Panggil API Xendit. `null` berarti permintaan tidak mendapat jawaban HTTP
     * (DNS gagal, koneksi putus, timeout).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: array<mixed>}|null
     */
    private function send(string $path, array $payload): ?array
    {
        try {
            $response = Http::withBasicAuth($this->secretKey(), '')
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout())
                ->post($this->url($path), $payload);
        } catch (\Throwable $error) {
            Log::warning('Cek rekening Xendit tidak terhubung', ['path' => $path, 'error' => $error->getMessage()]);

            return null;
        }

        $decoded = $response->json();

        return [$response->status(), is_array($decoded) ? $decoded : []];
    }

    /**
     * Terjemahkan jawaban Xendit. Jawaban 2xx memuat `is_found` dan nama di
     * `result.{holderKey}`; 401/403 (produk belum diaktifkan atau kunci salah),
     * 404, dan 5xx berarti penyedia tidak menjawab pertanyaan kita.
     *
     * @param  array<mixed>  $payload
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    private function interpret(int $status, array $payload, string $holderKey, string $manualHint): array
    {
        if ($status >= 200 && $status < 300) {
            $holder = trim((string) ($payload['result'][$holderKey] ?? $payload[$holderKey] ?? ''));

            if ($holder !== '') {
                return $this->result(true, 'verified', $holder, null);
            }

            if (($payload['is_found'] ?? null) === false) {
                return $this->result(
                    false,
                    'rejected',
                    null,
                    'Nomor rekening tidak ditemukan atau sudah tidak aktif. Periksa kembali nomor rekening Anda.',
                );
            }

            return $this->unavailable('Nama pemilik tidak dapat ditampilkan otomatis oleh penyedia. '.$manualHint);
        }

        Log::warning('Cek rekening Xendit tidak tersedia', [
            'status' => $status,
            'body' => mb_substr((string) json_encode($payload), 0, 300),
        ]);

        $reason = strtoupper((string) ($payload['error_code'] ?? '').' '.(string) ($payload['message'] ?? ''));

        if (in_array($status, [400, 422], true)
            && (str_contains($reason, 'NOT_FOUND') || str_contains($reason, 'INVALID_ACCOUNT') || str_contains($reason, 'NOT FOUND'))) {
            return $this->result(
                false,
                'rejected',
                null,
                'Nomor rekening tidak ditemukan atau sudah tidak aktif. Periksa kembali nomor rekening Anda.',
            );
        }

        // 401/403/404 = produk belum diaktifkan atau kunci salah; Xendit
        // menyembunyikan endpoint Data Services yang belum aktif di balik 404.
        return $this->unavailable(
            in_array($status, [401, 403, 404], true)
                ? 'Pengecekan nama rekening otomatis belum aktif.'
                : 'Pengecekan nama rekening otomatis sedang gangguan.',
        );
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.xendit.base_url'), '/').$path;
    }

    private function timeout(): int
    {
        return (int) config('services.xendit.timeout', 20);
    }

    private function secretKey(): string
    {
        return trim((string) config('services.xendit.secret_key'));
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
