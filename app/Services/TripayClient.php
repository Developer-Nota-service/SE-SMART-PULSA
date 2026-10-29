<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien API PPOB TriPay (https://tripay.id/api-docs).
 *
 * Baru dipakai untuk satu hal: mengambil daftar produk prabayar beserta harga
 * supplier, supaya harga modalnya bisa dibandingkan dengan Digiflazz.
 * Autentikasi memakai header `Authorization: Bearer {api_key}`.
 */
class TripayClient
{
    private const CACHE_KEY = 'tripay:products';

    public function isConfigured(): bool
    {
        return trim((string) config('tripay.api_key')) !== '';
    }

    /**
     * Daftar produk prabayar TriPay (di-cache beberapa menit).
     *
     * @return array{items: list<array{sku: string, name: string, price: int, available: bool}>, fetchedAt: string}
     */
    public function products(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('TriPay belum dikonfigurasi: isi TRIPAY_API_KEY.');
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['items'], $cached['fetchedAt'])) {
            return $cached;
        }

        try {
            $response = Http::withToken((string) config('tripay.api_key'))
                ->acceptJson()
                ->timeout(25)
                ->get(rtrim((string) config('tripay.base_url'), '/').'/pembelian/produk');
        } catch (\Throwable $exception) {
            throw new RuntimeException('Tidak bisa menghubungi TriPay: '.$exception->getMessage());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Jawaban TriPay bukan JSON.');
        }

        if ($response->failed() || ($body['success'] ?? false) !== true) {
            // Pesan TriPay biasanya sudah jelas ("Invalid API Key") — teruskan
            // apa adanya supaya admin tahu harus memperbaiki kuncinya.
            $message = trim((string) ($body['message'] ?? ''));

            throw new RuntimeException($message !== '' ? $message : 'TriPay membalas HTTP '.$response->status().'.');
        }

        $rows = $body['data'] ?? [];

        if (! is_array($rows)) {
            throw new RuntimeException('Daftar produk TriPay tidak dikenali.');
        }

        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['product_name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $items[] = [
                'sku' => trim((string) ($row['product_id'] ?? '')),
                'name' => $name,
                'price' => (int) ($row['price'] ?? 0),
                // status: 1 = tersedia, 0 = tidak tersedia.
                'available' => (string) ($row['status'] ?? '1') === '1',
            ];
        }

        $payload = ['items' => $items, 'fetchedAt' => now()->toIso8601String()];

        Cache::put(self::CACHE_KEY, $payload, (int) config('tripay.cache_seconds', 300));

        return $payload;
    }
}
