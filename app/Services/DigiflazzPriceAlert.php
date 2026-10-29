<?php

namespace App\Services;

use App\Support\DashboardContent;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi WhatsApp ke admin saat sinkron Digiflazz menemukan kenaikan
 * harga modal atau produk yang baru berstatus gangguan (harga modal seller
 * melewati `products.max_cost_price`, jadi produk tidak bisa dibeli). Nomor
 * tujuan diatur di konsol admin (`dashboard_settings` key
 * `priceAlertWhatsapp`, default nomor pemilik).
 *
 * Pesan hanya dikirim bila memang ada perubahan, supaya kuota Fonnte tidak
 * terpakai setiap 30 menit.
 */
class DigiflazzPriceAlert
{
    /** Maksimal produk yang dirinci dalam satu pesan. */
    private const MAX_ITEMS = 15;

    public function __construct(private readonly FonnteClient $fonnte)
    {
    }

    /**
     * @param  list<array{name: string, sku: string, old: int, new: int, max: int}>  $increases
     */
    public function send(array $increases): bool
    {
        if ($increases === []) {
            return false;
        }

        return $this->deliver(
            $this->message($increases),
            'Notifikasi kenaikan harga Digiflazz',
            count($increases),
        );
    }

    /**
     * Produk yang baru berstatus gangguan: harga modal seller melewati harga max
     * modal, sehingga produk tetap tayang tetapi tidak bisa dibeli.
     *
     * @param  list<array{name: string, sku: string, cost: int, max: int}>  $products
     */
    public function sendDisturbed(array $products): bool
    {
        if ($products === []) {
            return false;
        }

        return $this->deliver(
            $this->disturbedMessage($products),
            'Notifikasi produk gangguan Digiflazz',
            count($products),
        );
    }

    private function deliver(string $message, string $context, int $count): bool
    {
        $phone = (string) DashboardContent::settings()['priceAlertWhatsapp'];

        if ($phone === '') {
            Log::info($context.' tidak dikirim: nomor WhatsApp admin kosong.');

            return false;
        }

        try {
            $sent = $this->fonnte->send($phone, $message);
        } catch (\Throwable $exception) {
            Log::warning($context.' gagal: '.$exception->getMessage());

            return false;
        }

        Log::info($context, [
            'products' => $count,
            'sent' => $sent,
        ]);

        return $sent;
    }

    /**
     * @param  list<array{name: string, sku: string, old: int, new: int, max: int}>  $increases
     */
    private function message(array $increases): string
    {
        $lines = [
            '⚠️ *Kenaikan harga Digiflazz*',
            now()->timezone('Asia/Makassar')->format('d/m/Y H:i').' WITA',
            '',
        ];

        foreach (array_slice($increases, 0, self::MAX_ITEMS) as $index => $row) {
            $line = sprintf(
                '%d. %s (%s)%s   Rp%s → Rp%s (+Rp%s)',
                $index + 1,
                $row['name'],
                $row['sku'],
                PHP_EOL,
                $this->rupiah($row['old']),
                $this->rupiah($row['new']),
                $this->rupiah($row['new'] - $row['old']),
            );

            if ($row['max'] > 0 && $row['new'] > $row['max']) {
                $line .= ' · melewati harga max, produk otomatis tidak bisa dibeli';
            }

            $lines[] = $line;
        }

        if (count($increases) > self::MAX_ITEMS) {
            $lines[] = '…dan '.(count($increases) - self::MAX_ITEMS).' produk lainnya.';
        }

        $lines[] = '';
        $lines[] = 'Total '.count($increases).' produk naik. Harga dicek otomatis tiap 30 menit.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  list<array{name: string, sku: string, cost: int, max: int}>  $products
     */
    private function disturbedMessage(array $products): string
    {
        $lines = [
            '🚫 *Produk gangguan — tidak bisa dibeli*',
            now()->timezone('Asia/Makassar')->format('d/m/Y H:i').' WITA',
            '',
        ];

        foreach (array_slice($products, 0, self::MAX_ITEMS) as $index => $row) {
            $lines[] = sprintf(
                '%d. %s (%s)%s   modal Rp%s > max Rp%s (+Rp%s)',
                $index + 1,
                $row['name'],
                $row['sku'],
                PHP_EOL,
                $this->rupiah($row['cost']),
                $this->rupiah($row['max']),
                $this->rupiah($row['cost'] - $row['max']),
            );
        }

        if (count($products) > self::MAX_ITEMS) {
            $lines[] = '…dan '.(count($products) - self::MAX_ITEMS).' produk lainnya.';
        }

        $lines[] = '';
        $lines[] = 'Total '.count($products)
            .' produk berstatus gangguan. Naikkan harga max modal atau turunkan harga agar bisa dibeli lagi.';

        return implode(PHP_EOL, $lines);
    }

    private function rupiah(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
