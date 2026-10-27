<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengecekan nama pemilik rekening/e-wallet tujuan penarikan lewat **Iluma**
 * (Basic auth: API key sebagai username, tanpa password).
 *
 * - Bank: `POST /v1.2/identity/bank_account_validation_details`
 *   `{bank_account_number, bank_code: "ID_<BANK>"}` → `result.account_holder_name`.
 * - E-wallet: `POST /v0/identity/ewallet_account_data_requests`
 *   `{ewallet_account_number, ewallet_type: "GOPAY"}` → `result.ewallet_account_name`.
 *
 * Iluma memproses sebagian besar permintaan secara asinkron: balasan pertama bisa
 * `PENDING` dan hasil akhirnya dikirim lewat callback URL. Karena URL preview
 * sandbox berubah-ubah, kelas ini tidak mendaftarkan callback melainkan menanyakan
 * ulang hasilnya lewat `GET .../:id` beberapa kali.
 *
 * Bedakan dua jenis kegagalan:
 * - jawaban penyedia bahwa rekening tidak ada (`is_found: false`) → pengajuan diblokir;
 * - penyedia tidak bisa menjawab (kunci kosong/ditolak, 4xx/5xx, timeout) → hasil
 *   `configured: false`: pengguna mengisi nama sendiri dan catatan ditandai
 *   `unverified`. Satu kunci salah tidak boleh mematikan seluruh penarikan.
 */
class BankAccountVerifier
{
    /** Jumlah percobaan menanyakan ulang hasil `PENDING` (jeda 1 detik). */
    private const MAX_POLLS = 5;

    private const POLL_INTERVAL_US = 1000000;

    private const BANK_PATH = '/v1.2/identity/bank_account_validation_details';

    private const EWALLET_PATH = '/v0/identity/ewallet_account_data_requests';

    public function isConfigured(): bool
    {
        return trim((string) config('services.iluma.api_key')) !== '';
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    public function verify(string $channelType, string $providerCode, string $accountNumber): array
    {
        if (! $this->isConfigured()) {
            return $this->unavailable('Pengecekan nama rekening otomatis belum aktif.');
        }

        $cacheKey = "iluma:{$channelType}:{$providerCode}:{$accountNumber}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $result = $channelType === 'ewallet'
            ? $this->checkEwallet($providerCode, $accountNumber)
            : $this->checkBank($providerCode, $accountNumber);

        // Jawaban pasti (rekening valid/ditolak) disimpan 10 menit; masih diproses
        // hanya 10 detik supaya langsung dicek lagi; gangguan 1 menit.
        $ttl = match ($result['status']) {
            'verified', 'rejected' => now()->addMinutes(10),
            'pending' => now()->addSeconds(10),
            default => now()->addMinute(),
        };

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
        // Kode bank Iluma memakai awalan negara, mis. `bca` → `ID_BCA`.
        $response = $this->send(self::BANK_PATH, [
            'bank_account_number' => $accountNumber,
            'bank_code' => 'ID_'.strtoupper($providerCode),
        ]);

        if ($response === null) {
            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        [$status, $payload] = $response;

        $error = $this->errorResult($status, $payload);

        if ($error !== null) {
            return $error;
        }

        return $this->interpret($payload, self::BANK_PATH, 'account_holder_name');
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    private function checkEwallet(string $providerCode, string $accountNumber): array
    {
        $response = $this->send(self::EWALLET_PATH, [
            'ewallet_account_number' => $accountNumber,
            'ewallet_type' => strtoupper($providerCode),
        ]);

        if ($response === null) {
            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        [$status, $payload] = $response;

        $error = $this->errorResult($status, $payload);

        if ($error !== null) {
            return $error;
        }

        return $this->interpret($payload, self::EWALLET_PATH, 'ewallet_account_name');
    }

    /**
     * Terjemahkan payload Iluma menjadi hasil pengecekan. `PENDING` ditanyakan
     * ulang lewat `GET {path}/{id}` sampai `MAX_POLLS` kali.
     *
     * @param  array<mixed>  $payload
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    private function interpret(array $payload, string $path, string $holderKey): array
    {
        for ($attempt = 0; $attempt <= self::MAX_POLLS; $attempt++) {
            $state = strtoupper((string) ($payload['status'] ?? ''));
            $id = (string) ($payload['id'] ?? '');

            if (in_array($state, ['PENDING', 'WAITING_ON_SOURCE'], true) && $id !== '' && $attempt < self::MAX_POLLS) {
                usleep(self::POLL_INTERVAL_US);
                $response = $this->send($path.'/'.rawurlencode($id), null, 'GET');

                if ($response === null) {
                    return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
                }

                [$status, $polled] = $response;

                $error = $this->errorResult($status, $polled);

                if ($error !== null) {
                    return $error;
                }

                $payload = $polled;

                continue;
            }

            if (in_array($state, ['PENDING', 'WAITING_ON_SOURCE'], true)) {
                return [
                    'configured' => true,
                    'verified' => false,
                    'status' => 'pending',
                    'accountHolder' => null,
                    'message' => 'Pengecekan nama rekening masih diproses. Tunggu beberapa detik lalu coba lagi.',
                ];
            }

            if ($state === 'FAILED') {
                Log::warning('Cek rekening Iluma gagal diproses', [
                    'reason' => $payload['failure_reason'] ?? null,
                ]);

                return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
            }

            $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
            $holder = trim((string) ($result[$holderKey] ?? ''));

            if ($holder !== '') {
                return $this->result(true, 'verified', $holder, null);
            }

            if (($result['is_found'] ?? null) === false) {
                return $this->result(
                    false,
                    'rejected',
                    null,
                    'Nomor rekening tidak ditemukan. Periksa kembali nomor rekening Anda.',
                );
            }

            // Rekening ada tetapi nama tidak tersedia (mis. e-wallet belum KYC).
            return $this->unavailable(
                'Nama pemilik tidak dapat ditampilkan otomatis oleh penyedia. Masukkan nama sesuai buku tabungan/aplikasi.',
            );
        }

        return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
    }

    /**
     * Panggil API Iluma. `null` berarti permintaan tidak mendapat jawaban HTTP
     * (DNS gagal, koneksi putus, timeout).
     *
     * @param  array<string, string>|null  $payload
     * @return array{0: int, 1: array<mixed>}|null
     */
    private function send(string $path, ?array $payload, string $method = 'POST'): ?array
    {
        $url = rtrim((string) config('services.iluma.base_url'), '/').$path;
        $key = trim((string) config('services.iluma.api_key'));

        try {
            $request = Http::withBasicAuth($key, '')
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.iluma.timeout', 20));

            $response = $method === 'GET'
                ? $request->get($url)
                : $request->post($url, $payload ?? []);
        } catch (\Throwable $error) {
            Log::warning('Cek rekening Iluma tidak terhubung', ['path' => $path, 'error' => $error->getMessage()]);

            return null;
        }

        $decoded = $response->json();

        return [$response->status(), is_array($decoded) ? $decoded : []];
    }

    /**
     * 401/403 (kunci salah dikonfigurasi), 400 (bank/e-wallet tak didukung), 5xx
     * atau balasan bukan JSON: penyedia tidak menjawab pertanyaan kita, jadi
     * jangan memblokir pengajuan.
     *
     * @param  array<mixed>  $payload
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}|null
     */
    private function errorResult(int $status, array $payload): ?array
    {
        if ($status >= 200 && $status < 300 && $payload !== []) {
            return null;
        }

        Log::warning('Cek rekening Iluma tidak tersedia', [
            'status' => $status,
            'body' => mb_substr((string) json_encode($payload), 0, 300),
        ]);

        return $this->unavailable(
            $status === 401 || $status === 403
                ? 'Pengecekan nama rekening otomatis belum aktif.'
                : 'Pengecekan nama rekening otomatis sedang gangguan.',
        );
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
