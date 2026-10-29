<?php

namespace App\Services;

use App\Support\DashboardContent;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi WhatsApp ke admin saat sinkron Digiflazz menemukan kenaikan
 * harga modal. Nomor tujuan diatur di konsol admin (`dashboard_settings`
 * key `priceAlertWhatsapp`, default nomor pemilik).
 *
 * Pesan hanya dikirim bila memang ada produk yang naik, supaya kuota Fonnte
 * tidak terpakai setiap 30 menit.
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

        $phone = (string) DashboardContent::settings()['priceAlertWhatsapp'];

        if ($phone === '') {
            Log::info('Notifikasi kenaikan harga Digiflazz tidak dikirim: nomor WhatsApp admin kosong.');

            return false;
        }

        try {
            $sent = $this->fonnte->send($phone, $this->message($increases));
        } catch (\Throwable $exception) {
            Log::warning('Notifikasi kenaikan harga Digiflazz gagal: '.$exception->getMessage());

            return false;
        }

        Log::info('Notifikasi kenaikan harga Digiflazz', [
            'products' => count($increases),
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

    private function rupiah(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
