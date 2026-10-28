<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cek nama pemilik rekening bank & e-wallet tujuan penarikan lewat **Durianpay**
 * SNAP API — penyedia yang punya endpoint nama untuk bank maupun e-wallet.
 *
 * - Token B2B: `POST /v1.0/access-token/b2b` dengan `X-SIGNATURE` = RSA-SHA256
 *   dari `{clientKey}|{timestamp}` memakai private key merchant (public key-nya
 *   diunggah di Dashboard Durianpay → Settings → API Keys), header
 *   `X-CLIENT-KEY`, body `{"grantType":"AUTHORIZATION_CODE"}`.
 * - Bank: `POST /v1.0/account-inquiry-external`
 *   `{beneficiaryAccountNo, beneficiaryBankCode}` → `beneficiaryAccountName`
 *   (`beneficiaryBankCode` = kode bank SNAP, mis. BCA `014`).
 * - E-wallet: `POST /v1.0/emoney/account-inquiry`
 *   `{customerNumber, amount, partnerReferenceNo, additionalInfo.platformCode}`
 *   → `customerName`. `amount.value = "1"` mematikan validasi nominal karena
 *   kita hanya meminta nama.
 *
 * Signature transaksi = HMAC-SHA512 (client secret, base64) dari
 * `POST:{path}:{accessToken}:{sha256hex(minifiedBody)}:{timestamp}`.
 *
 * Bedakan dua jenis kegagalan:
 * - penyedia menjawab rekening tidak ada (HTTP 404/409) → pengajuan diblokir;
 * - penyedia tidak bisa menjawab (kunci kosong/ditolak 401/403, 5xx, timeout) →
 *   hasil `configured: false`: pengguna mengisi nama sendiri dan catatan ditandai
 *   `unverified`. Satu kunci salah tidak boleh mematikan seluruh penarikan.
 */
class BankAccountVerifier
{
    private const TOKEN_PATH = '/v1.0/access-token/b2b';

    private const BANK_PATH = '/v1.0/account-inquiry-external';

    private const EWALLET_PATH = '/v1.0/emoney/account-inquiry';

    /** Kode bank SNAP untuk daftar bank di App\Support\TransferChannels. */
    private const BANK_CODES = [
        'bca' => '014',
        'bni' => '009',
        'bri' => '002',
        'mandiri' => '008',
        'permata' => '013',
        'cimb' => '022',
        'danamon' => '011',
        'btn' => '200',
        'bsi' => '451',
        'jago' => '542',
        'seabank' => '535',
        'blu' => '501',
    ];

    /** Status HTTP permintaan token terakhir (401/403 = kunci salah). */
    private ?int $tokenStatus = null;

    public function isConfigured(): bool
    {
        return $this->clientKey() !== ''
            && $this->clientSecret() !== ''
            && $this->privateKey() !== '';
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    public function verify(string $channelType, string $providerCode, string $accountNumber): array
    {
        if (! $this->isConfigured()) {
            return $this->unavailable('Pengecekan nama rekening otomatis belum aktif.');
        }

        $cacheKey = "durianpay:{$channelType}:{$providerCode}:{$accountNumber}";
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
            'beneficiaryAccountNo' => $accountNumber,
            'beneficiaryBankCode' => $bankCode,
            'additionalInfo' => $this->additionalInfo(),
        ]);

        if ($response === null) {
            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        [$status, $payload] = $response;

        $error = $this->errorResult($status, $payload);

        if ($error !== null) {
            return $error;
        }

        $holder = trim((string) ($payload['beneficiaryAccountName'] ?? ''));

        return $holder !== ''
            ? $this->result(true, 'verified', $holder, null)
            : $this->unavailable('Nama pemilik tidak dapat ditampilkan otomatis oleh penyedia. Masukkan nama sesuai buku tabungan.');
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    private function checkEwallet(string $providerCode, string $accountNumber): array
    {
        $response = $this->send(self::EWALLET_PATH, [
            'customerNumber' => $accountNumber,
            // "1" mematikan validasi nominal: kita hanya meminta nama pemilik.
            'amount' => ['value' => '1', 'currency' => 'IDR'],
            'partnerReferenceNo' => 'INQ'.now()->format('YmdHis').random_int(1000, 9999),
            'additionalInfo' => $this->additionalInfo() + ['platformCode' => $providerCode],
        ]);

        if ($response === null) {
            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        [$status, $payload] = $response;

        $error = $this->errorResult($status, $payload);

        if ($error !== null) {
            return $error;
        }

        $holder = trim((string) ($payload['customerName'] ?? ''));

        return $holder !== ''
            ? $this->result(true, 'verified', $holder, null)
            : $this->unavailable('Nama pemilik tidak dapat ditampilkan otomatis oleh penyedia. Masukkan nama sesuai aplikasi e-wallet.');
    }

    /** @return array{deviceId: string, channel: string} */
    private function additionalInfo(): array
    {
        return ['deviceId' => bin2hex(random_bytes(6)), 'channel' => 'mobilephone'];
    }

    /**
     * Panggil API Durianpay. `null` berarti permintaan tidak mendapat jawaban HTTP
     * (DNS gagal, koneksi putus, timeout) atau token tidak bisa dibuat.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: array<mixed>}|null
     */
    private function send(string $path, array $payload): ?array
    {
        $token = $this->accessToken();

        if ($token === null) {
            // Kunci ditolak: laporkan statusnya supaya pesannya "belum aktif".
            return $this->tokenStatus === null ? null : [$this->tokenStatus, []];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $timestamp = $this->timestamp();
        $signature = base64_encode(hash_hmac(
            'sha512',
            'POST:'.$path.':'.$token.':'.strtolower(hash('sha256', $body)).':'.$timestamp,
            $this->clientSecret(),
            true,
        ));

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'X-TIMESTAMP' => $timestamp,
                    'X-SIGNATURE' => $signature,
                    'X-PARTNER-ID' => $this->clientKey(),
                    'X-EXTERNAL-ID' => bin2hex(random_bytes(8)),
                    'CHANNEL-ID' => (string) config('services.durianpay.channel_id', '95221'),
                ])
                ->withBody($body, 'application/json')
                ->acceptJson()
                ->timeout($this->timeout())
                ->post($this->url($path));
        } catch (\Throwable $error) {
            Log::warning('Cek rekening Durianpay tidak terhubung', ['path' => $path, 'error' => $error->getMessage()]);

            return null;
        }

        $decoded = $response->json();

        return [$response->status(), is_array($decoded) ? $decoded : []];
    }

    /**
     * Token B2B berlaku 900 detik; disimpan di cache sampai 60 detik sebelum
     * kedaluwarsa supaya tidak minta token di setiap pengecekan.
     */
    private function accessToken(): ?string
    {
        $cached = Cache::get('durianpay:token');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $timestamp = $this->timestamp();
        $signature = $this->rsaSignature($this->clientKey().'|'.$timestamp);

        if ($signature === null) {
            Log::warning('Private key Durianpay tidak bisa dipakai menandatangani permintaan token');
            $this->tokenStatus = 401;

            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-TIMESTAMP' => $timestamp,
                'X-SIGNATURE' => $signature,
                'X-CLIENT-KEY' => $this->clientKey(),
            ])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout())
                ->post($this->url(self::TOKEN_PATH), ['grantType' => 'AUTHORIZATION_CODE']);
        } catch (\Throwable $error) {
            Log::warning('Token Durianpay gagal diminta', ['error' => $error->getMessage()]);
            $this->tokenStatus = null;

            return null;
        }

        $this->tokenStatus = $response->status();
        $payload = $response->json();
        $token = is_array($payload) ? (string) ($payload['accessToken'] ?? '') : '';

        if (! $response->successful() || $token === '') {
            Log::warning('Token Durianpay ditolak', [
                'status' => $response->status(),
                'body' => mb_substr((string) json_encode($payload), 0, 300),
            ]);

            return null;
        }

        $expiresIn = (int) ($payload['expiresIn'] ?? 900);
        Cache::put('durianpay:token', $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    private function rsaSignature(string $data): ?string
    {
        $signature = '';

        if (! openssl_sign($data, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return base64_encode($signature);
    }

    /**
     * 401/403 (kunci salah), 5xx, atau balasan bukan JSON: penyedia tidak
     * menjawab pertanyaan kita, jadi jangan memblokir pengajuan. 404/409 =
     * penyedia memastikan rekening tidak ada/tertutup → blokir pengajuan.
     *
     * @param  array<mixed>  $payload
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}|null
     */
    private function errorResult(int $status, array $payload): ?array
    {
        if ($status >= 200 && $status < 300 && $payload !== []) {
            return null;
        }

        Log::warning('Cek rekening Durianpay tidak tersedia', [
            'status' => $status,
            'body' => mb_substr((string) json_encode($payload), 0, 300),
        ]);

        if ($status === 404 || $status === 409) {
            return $this->result(
                false,
                'rejected',
                null,
                'Nomor rekening tidak ditemukan atau sudah tidak aktif. Periksa kembali nomor rekening Anda.',
            );
        }

        return $this->unavailable(
            $status === 401 || $status === 403
                ? 'Pengecekan nama rekening otomatis belum aktif.'
                : 'Pengecekan nama rekening otomatis sedang gangguan.',
        );
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.durianpay.base_url'), '/').$path;
    }

    private function timestamp(): string
    {
        return now()->format('Y-m-d\TH:i:s.vP');
    }

    private function timeout(): int
    {
        return (int) config('services.durianpay.timeout', 20);
    }

    private function clientKey(): string
    {
        return trim((string) config('services.durianpay.client_key'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('services.durianpay.client_secret'));
    }

    /** Private key RSA merchant, boleh PEM mentah atau base64 dari file .pem. */
    private function privateKey(): string
    {
        $value = trim((string) config('services.durianpay.private_key'));

        if ($value === '') {
            return '';
        }

        if (str_contains($value, 'BEGIN')) {
            return str_replace('\n', "\n", $value);
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : trim($decoded);
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
