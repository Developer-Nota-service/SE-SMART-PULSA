<?php

namespace App\Console\Commands;

use App\Services\DigiflazzClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menarik daftar harga Digiflazz lalu menyimpannya sebagai produk nyata
 * (`products.is_demo = false`).
 *
 * Harga: reseller = harga supplier + margin (`DIGIFLAZZ_MARGIN_PERCENT`),
 * publik = reseller + 5% — dibulatkan ke atas per Rp100 seperti produk demo.
 * Produk yang tidak lagi dikirim Digiflazz dinonaktifkan (tidak dihapus) supaya
 * riwayat transaksi lama tetap utuh.
 */
class SyncDigiflazzProducts extends Command
{
    protected $signature = 'digiflazz:sync-products';

    protected $description = 'Sinkronkan daftar produk & harga dari Digiflazz';

    public function handle(DigiflazzClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('Digiflazz belum dikonfigurasi: isi DIGIFLAZZ_USERNAME dan DIGIFLAZZ_API_KEY.');

            return self::FAILURE;
        }

        try {
            $rows = $client->priceList();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $margin = (float) config('digiflazz.margin_percent');
        $skus = [];
        $active = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $sku = trim((string) ($row['buyer_sku_code'] ?? ''));
            $category = $this->categoryFor($row);
            $cost = (int) ($row['price'] ?? 0);

            if ($sku === '' || $category === null || $cost <= 0) {
                $skipped++;

                continue;
            }

            $reseller = $this->roundUp($cost * (1 + $margin / 100));
            $retail = $this->roundUp($reseller * 1.05);
            $enabled = (bool) ($row['buyer_product_status'] ?? false) && (bool) ($row['seller_product_status'] ?? false);

            DB::table('products')->updateOrInsert(
                ['sku' => $sku],
                [
                    'category' => $category,
                    'operator' => trim((string) ($row['brand'] ?? '')),
                    'name' => trim((string) ($row['product_name'] ?? $sku)),
                    'price' => $reseller,
                    'retail_price' => $retail,
                    'active' => $enabled,
                    'is_demo' => false,
                    'sort_order' => 0,
                ],
            );

            $skus[] = $sku;

            if ($enabled) {
                $active++;
            }
        }

        $deactivated = 0;

        if ($skus !== []) {
            $deactivated = DB::table('products')
                ->where('is_demo', false)
                ->whereNotIn('sku', $skus)
                ->where('active', true)
                ->update(['active' => false]);
        }

        $this->info(sprintf(
            'Digiflazz: %d produk tersimpan (%d aktif), %d dilewati, %d dinonaktifkan.',
            count($skus),
            $active,
            $skipped,
            $deactivated,
        ));

        return self::SUCCESS;
    }

    private function roundUp(float $value): int
    {
        return (int) (ceil($value / 100) * 100);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function categoryFor(array $row): ?string
    {
        $category = strtolower(trim((string) ($row['category'] ?? '')));
        $name = strtolower(trim((string) ($row['product_name'] ?? '')));

        return match (true) {
            $category === 'pulsa' => 'pulsa',
            str_contains($category, 'data') => 'data',
            $category === 'pln' && str_contains($name, 'token') => 'pln_token',
            str_contains($category, 'money') => 'ewallet',
            str_contains($category, 'game') => 'game',
            $category === 'voucher' => 'voucher',
            // Pascabayar, masa aktif, TV, eSIM belum didukung alur aplikasi ini.
            default => null,
        };
    }
}
