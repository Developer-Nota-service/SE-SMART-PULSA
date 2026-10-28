<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Klien buyer API Digiflazz (https://developer.digiflazz.com/api/buyer/).
 *
 * Signature: md5(username + apiKey + "pricelist") untuk daftar harga dan
 * md5(username + apiKey + ref_id) untuk topup/cek status.
 */
class DigiflazzClient
{
    public function isConfigured(): bool
    {
        return $this->username() !== '' && $this->apiKey() !== '';
    }

    /**
     * Daftar produk prabayar beserta harga supplier.
     *
     * @return list<array<string, mixed>>
     */
    public function priceList(): array
    {
        $response = $this->request('/v1/price-list', [
            'cmd' => 'prepaid',
            'username' => $this->username(),
            'sign' => md5($this->username().$this->apiKey().'pricelist'),
        ]);

        $rows = $response['data'] ?? [];

        if (! is_array($rows)) {
            throw new RuntimeException('Daftar harga Digiflazz tidak dikenali.');
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Kirim topup. Memakai ref_id yang sama berarti mengecek status transaksi
     * tersebut (perilaku resmi Digiflazz).
     *
     * @return array<string, mixed> data hasil transaksi (status, rc, sn, message, ...)
     */
    public function topup(string $refId, string $sku, string $customerNumber, ?int $maxPrice = null): array
    {
        $payload = [
            'username' => $this->username(),
            'buyer_sku_code' => $sku,
            'customer_no' => $customerNumber,
            'ref_id' => $refId,
            'sign' => md5($this->username().$this->apiKey().$refId),
        ];

        if (config('digiflazz.testing')) {
            $payload['testing'] = true;
        }

        if ($maxPrice !== null && $maxPrice > 0) {
            $payload['max_price'] = $maxPrice;
        }

        $response = $this->request('/v1/transaction', $payload);
        $data = $response['data'] ?? null;

        if (! is_array($data) || ! isset($data['status'])) {
            $message = is_array($data) ? ($data['message'] ?? '') : '';
            $code = is_array($data) ? ($data['rc'] ?? '') : '';

            throw new RuntimeException(trim('Digiflazz menolak transaksi. '.$code.' '.$message));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Digiflazz belum dikonfigurasi: isi DIGIFLAZZ_USERNAME dan DIGIFLAZZ_API_KEY.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(25)
                ->post(rtrim((string) config('digiflazz.url'), '/').$path, $payload);
        } catch (\Throwable $exception) {
            Log::warning('Gagal menghubungi Digiflazz: '.$exception->getMessage());

            throw new RuntimeException('Tidak bisa menghubungi Digiflazz: '.$exception->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('Digiflazz membalas HTTP '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Jawaban Digiflazz bukan JSON.');
        }

        return $body;
    }

    private function username(): string
    {
        return trim((string) config('digiflazz.username'));
    }

    private function apiKey(): string
    {
        return trim((string) config('digiflazz.api_key'));
    }
}
