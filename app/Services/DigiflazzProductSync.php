<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Menarik daftar harga Digiflazz lalu menyimpannya sebagai produk nyata
 * (`products.is_demo = false`).
 *
 * Harga modal supplier (`cost_price`) dan provider (`seller_name`) selalu
 * diperbarui, tetapi **harga jual yang sudah diatur admin tidak ditimpa** —
 * tanpa itu, tombol sinkron di konsol admin akan menghapus semua harga yang
 * sudah disesuaikan. Harga jual hanya dihitung otomatis untuk produk baru:
 * reseller = modal + margin (`DIGIFLAZZ_MARGIN_PERCENT`), publik = reseller + 5%,
 * keduanya dibulatkan ke atas per Rp100 seperti produk demo.
 *
 * Produk yang tidak lagi dikirim Digiflazz dinonaktifkan (tidak dihapus) supaya
 * riwayat transaksi lama tetap utuh.
 */
class DigiflazzProductSync
{
    public function __construct(private readonly DigiflazzClient $client)
    {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return array{products: int, active: int, skipped: int, deactivated: int}
     */
    public function sync(): array
    {
        $rows = $this->client->priceList();

        $margin = (float) config('digiflazz.margin_percent');
        $now = now();
        $skus = [];
        $active = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $margin, $now, &$skus, &$active, &$skipped): void {
            foreach ($rows as $row) {
                $sku = trim((string) ($row['buyer_sku_code'] ?? ''));
                $category = $this->categoryFor($row);
                $cost = (int) ($row['price'] ?? 0);

                if ($sku === '' || $category === null || $cost <= 0) {
                    $skipped++;

                    continue;
                }

                $enabled = (bool) ($row['buyer_product_status'] ?? false)
                    && (bool) ($row['seller_product_status'] ?? false);

                $supplier = [
                    'category' => $category,
                    'operator' => trim((string) ($row['brand'] ?? '')),
                    'name' => trim((string) ($row['product_name'] ?? $sku)),
                    // Petunjuk input dari seller (mis. "gabungan user_id dan
                    // zone_id") — ditampilkan di halaman beli.
                    'instruction' => $this->instruction((string) ($row['desc'] ?? '')),
                    'cost_price' => $cost,
                    'seller_name' => $this->seller((string) ($row['seller_name'] ?? '')),
                    'active' => $enabled,
                    'is_demo' => false,
                    'cost_synced_at' => $now,
                ];

                $existing = DB::table('products')->where('sku', $sku)->first(['id', 'price']);

                if ($existing === null) {
                    DB::table('products')->insert($supplier + $this->autoPrices($cost, $margin) + ['sku' => $sku]);
                } else {
                    DB::table('products')->where('id', $existing->id)->update($supplier);
                }

                $skus[] = $sku;

                if ($enabled) {
                    $active++;
                }
            }
        });

        $deactivated = 0;

        if ($skus !== []) {
            $deactivated = DB::table('products')
                ->where('is_demo', false)
                ->whereNotIn('sku', $skus)
                ->where('active', true)
                ->update(['active' => false]);
        }

        return [
            'products' => count($skus),
            'active' => $active,
            'skipped' => $skipped,
            'deactivated' => $deactivated,
        ];
    }

    /**
     * Harga jual awal untuk produk baru.
     *
     * @return array{price: int, retail_price: int}
     */
    private function autoPrices(int $cost, float $margin): array
    {
        $reseller = $this->roundUp($cost * (1 + $margin / 100));

        return [
            'price' => $reseller,
            'retail_price' => $this->roundUp($reseller * 1.05),
        ];
    }

    private function seller(string $name): ?string
    {
        $name = trim($name);

        return $name === '' ? null : $name;
    }

    private function instruction(string $desc): ?string
    {
        $desc = trim(preg_replace('/\s+/', ' ', $desc) ?? '');

        return $desc === '' || $desc === '-' ? null : $desc;
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
        $brand = strtolower(trim((string) ($row['brand'] ?? '')));

        return match (true) {
            $category === 'pulsa' => 'pulsa',
            str_contains($category, 'data') => 'data',
            $category === 'pln' && str_contains($name, 'token') => 'pln_token',
            str_contains($category, 'money') => 'ewallet',
            str_contains($category, 'game') => 'game',
            // Voucher Telkomsel dari Digiflazz adalah voucher inject: kodenya
            // dimasukkan sendiri ke voucher/kartu kosong, jadi dipisah kategorinya.
            $category === 'voucher' && (str_contains($brand, 'telkomsel') || str_contains($name, 'telkomsel')) => 'voucher_inject',
            $category === 'voucher' => 'voucher',
            // Pascabayar, masa aktif, TV, eSIM belum didukung alur aplikasi ini.
            default => null,
        };
    }
}
