<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Biaya layanan Midtrans per metode pembayaran (config `midtrans_fees`).
 *
 * Satu-satunya sumber aturan biaya: dipakai pemilih metode di frontend,
 * pembuatan pembayaran (deposit & beli langsung), dan pencocokan nominal di
 * webhook. Nominal selalu dihitung server — jangan percaya angka dari browser.
 */
class PaymentFee
{
    /**
     * Daftar kanal untuk pemilih metode pembayaran.
     *
     * @return array<int, array{code: string, label: string, percent: float, flat: int}>
     */
    public static function channels(): array
    {
        $channels = [];

        foreach ((array) config('midtrans_fees.channels', []) as $code => $channel) {
            $channels[] = [
                'code' => (string) $code,
                'label' => (string) ($channel['label'] ?? $code),
                'percent' => (float) ($channel['percent'] ?? 0),
                'flat' => (int) ($channel['flat'] ?? 0),
            ];
        }

        return $channels;
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, (array) config('midtrans_fees.channels', []));
    }

    /**
     * Total tagihan Midtrans: nilai pesanan + biaya layanan, dibulatkan ke atas
     * per rupiah supaya dana bersih tidak kurang sedikit pun.
     */
    public static function gross(int $amount, string $code): int
    {
        $channel = self::channel($code);
        $percent = min(99.0, max(0.0, (float) ($channel['percent'] ?? 0)));
        $flat = max(0, (int) ($channel['flat'] ?? 0));

        return (int) ceil(($amount + $flat) / (1 - $percent / 100));
    }

    public static function fee(int $amount, string $code): int
    {
        return max(0, self::gross($amount, $code) - $amount);
    }

    /**
     * Kode metode Snap yang dibuka untuk satu kanal.
     *
     * @return array<int, string>
     */
    public static function enabledPayments(string $code): array
    {
        return array_values((array) (self::channel($code)['enabled_payments'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function channel(string $code): array
    {
        $channels = (array) config('midtrans_fees.channels', []);

        if (! isset($channels[$code])) {
            throw new InvalidArgumentException('Metode pembayaran tidak dikenal.');
        }

        return (array) $channels[$code];
    }
}
