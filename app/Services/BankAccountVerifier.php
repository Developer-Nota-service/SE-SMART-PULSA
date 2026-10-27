<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengecekan nama pemilik rekening/e-wallet tujuan penarikan lewat CekRek
 * Netovas (`POST {NETOVAS_CEKREK_URL}` dengan form `account_bank` +
 * `account_number`).
 *
 * Penyedia mengembalikan nama pemilik rekening bila nomornya valid, atau pesan
 * penolakan bila nomor tidak ditemukan. Bedakan dua jenis kegagalan:
 *
 * - nomor ditolak penyedia (jawaban 2xx tanpa nama) → pengajuan diblokir;
 * - penyedia tidak menjawab (DNS gagal, timeout, 401/403/5xx, bukan JSON) →
 *   hasil `configured: false`: pengguna tetap bisa mengajukan penarikan dengan
 *   nama yang diketik sendiri, dan catatannya ditandai `unverified` untuk
 *   diperiksa manual. Satu kunci/URL salah tidak boleh mematikan penarikan.
 */
class BankAccountVerifier
{
    /** Kunci JSON yang umum dipakai penyedia untuk nama pemilik rekening. */
    private const HOLDER_KEYS = [
        'account_name',
        'account_holder',
        'accountname',
        'accountholder',
        'holder',
        'customer_name',
        'customername',
        'name',
        'nama',
    ];

    /** Kunci JSON untuk pesan penolakan. */
    private const MESSAGE_KEYS = ['message', 'error_message', 'errormessage', 'error', 'remark', 'detail'];

    public function isConfigured(): bool
    {
        return trim((string) config('services.netovas.cekrek.url')) !== '';
    }

    /**
     * @return array{configured: bool, verified: bool, status: string, accountHolder: ?string, message: ?string}
     */
    public function verify(string $providerCode, string $accountNumber): array
    {
        if (! $this->isConfigured()) {
            return $this->unavailable('Pengecekan nama rekening otomatis belum aktif.');
        }

        $cacheKey = "cekrek:{$providerCode}:{$accountNumber}";
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
        $url = (string) config('services.netovas.cekrek.url');
        $key = trim((string) config('services.netovas.cekrek.key'));

        try {
            $request = Http::acceptJson()
                ->asForm()
                ->timeout((int) config('services.netovas.cekrek.timeout', 15));

            if ($key !== '') {
                $request = $request->withToken($key);
            }

            $response = $request->post($url, [
                'account_bank' => $providerCode,
                'account_number' => $accountNumber,
            ]);
        } catch (\Throwable $error) {
            Log::warning('Cek rekening CekRek gagal', ['bank' => $providerCode, 'error' => $error->getMessage()]);

            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        $decoded = $response->json();
        $payload = is_array($decoded) ? $decoded : [];

        $holder = self::extractHolder($payload);

        if ($response->successful() && $holder !== '') {
            return $this->result(true, 'verified', $holder, null);
        }

        // URL/kunci belum benar atau layanan bermasalah: bukan bukti nomor
        // rekening salah, jadi jangan memblokir pengajuan.
        if (! $response->successful()) {
            Log::warning('Cek rekening CekRek tidak tersedia', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return $this->unavailable('Pengecekan nama rekening otomatis sedang gangguan.');
        }

        $message = self::extractMessage($payload);

        if ($message === '') {
            $message = 'Nomor rekening tidak dapat diverifikasi. Periksa kembali nomor rekening Anda.';
        }

        return $this->result(false, 'rejected', null, $message);
    }

    /**
     * Cari nama pemilik rekening di struktur JSON apa pun (bersarang di dalam
     * `data`, `result`, dan sebagainya).
     *
     * @param  array<mixed>  $payload
     */
    private static function extractHolder(array $payload): string
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $found = self::extractHolder($value);

                if ($found !== '') {
                    return $found;
                }

                continue;
            }

            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));

            if (in_array($normalized, self::HOLDER_KEYS, true) && is_scalar($value)) {
                $name = trim((string) $value);

                // `name` pada beberapa penyedia berisi nama bank, bukan pemilik.
                if ($name !== '' && ! ctype_digit($name)) {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function extractMessage(array $payload): string
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $found = self::extractMessage($value);

                if ($found !== '') {
                    return $found;
                }

                continue;
            }

            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));

            if (in_array($normalized, self::MESSAGE_KEYS, true) && is_scalar($value)) {
                $message = trim((string) $value);

                if ($message !== '') {
                    return $message;
                }
            }
        }

        return '';
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
