<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MidtransService
{
    /**
     * Placeholder pengembangan dari .env.base44-defaults. Nilai ini bukan
     * kredensial nyata dan sengaja ditolak saat APP_ENV=production.
     */
    private const PLACEHOLDER_KEY = 'SB-Mid-server-BASE44DEVPLACEHOLDER';

    public function isConfigured(): bool
    {
        $key = (string) config('services.midtrans.server_key');

        if ($key === '') {
            return false;
        }

        return ! ($key === self::PLACEHOLDER_KEY && app()->environment('production'));
    }

    /**
     * Membuat transaksi Snap dan mengembalikan halaman pembayaran Midtrans.
     *
     * @param  array{name?: string|null, email?: string|null, phone?: string|null}  $customer
     * @return array{token: string, redirectUrl: string}
     */
    public function createSnapPayment(string $orderId, int $amount, string $itemName, array $customer): array
    {
        $key = (string) config('services.midtrans.server_key');

        // Placeholder sandbox tetap dipakai untuk menguji webhook, tetapi bukan
        // kredensial yang sah untuk memanggil API Midtrans.
        if (! $this->isConfigured() || $key === self::PLACEHOLDER_KEY) {
            throw new RuntimeException('Pembayaran Midtrans belum dikonfigurasi. Isi MIDTRANS_SERVER_KEY.');
        }

        $response = Http::withBasicAuth($key, '')
            ->acceptJson()
            ->timeout(20)
            ->post($this->snapBaseUrl().'/snap/v1/transactions', [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => $amount,
                ],
                'item_details' => [[
                    'id' => $orderId,
                    'price' => $amount,
                    'quantity' => 1,
                    'name' => mb_substr($itemName, 0, 50),
                ]],
                'customer_details' => array_filter([
                    'first_name' => $customer['name'] ?? null,
                    'email' => $customer['email'] ?? null,
                    'phone' => $customer['phone'] ?? null,
                ]),
                'callbacks' => [
                    'finish' => $this->finishUrl(),
                ],
            ]);

        if ($response->failed() || ! $response->json('redirect_url')) {
            Log::warning('Midtrans Snap gagal', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Gagal membuat pembayaran Midtrans.');
        }

        return [
            'token' => (string) $response->json('token'),
            'redirectUrl' => (string) $response->json('redirect_url'),
        ];
    }

    public function verifySignature(string $orderId, string $statusCode, string $grossAmount, string $signature): bool
    {
        $key = (string) config('services.midtrans.server_key');

        if ($key === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash('sha512', $orderId.$statusCode.$grossAmount.$key), $signature);
    }

    private function snapBaseUrl(): string
    {
        return config('services.midtrans.is_production')
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com';
    }

    private function finishUrl(): string
    {
        $configured = (string) config('services.midtrans.finish_url');

        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/app/transaksi';
    }
}
