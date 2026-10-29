<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
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
 * Produk yang baru muncul di daftar Digiflazz langsung ditambahkan, dan kode
 * produk mengikuti `buyer_sku_code` supplier. Bila seller mengganti SKU sebuah
 * produk (SKU lama hilang, nama + seller sama), baris produk yang sudah ada
 * ikut diganti kodenya — id, harga jual, dan riwayat transaksinya tetap utuh.
 *
 * Produk yang tidak lagi dikirim Digiflazz dinonaktifkan (tidak dihapus) supaya
 * riwayat transaksi lama tetap utuh.
 *
 * Digiflazz membatasi pengecekan pricelist **maksimal 1x per 5 menit**
 * (`rc 83` "limitasi pengecekan pricelist"). Karena itu setiap tarikan menyalakan
 * jeda lokal `cooldownRemaining()` supaya tombol admin maupun penjadwal tidak
 * menembak API supplier berkali-kali dan menghabiskan kuota.
 */
class DigiflazzProductSync
{
    /** Jeda minimal antar tarikan pricelist (batas Digiflazz: 1x per 5 menit). */
    public const COOLDOWN_SECONDS = 300;

    private const COOLDOWN_KEY = 'digiflazz:pricelist-cooldown';

    public function __construct(private readonly DigiflazzClient $client)
    {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /** Sisa detik sebelum pricelist boleh ditarik lagi (0 = boleh sekarang). */
    public function cooldownRemaining(): int
    {
        $until = (int) Cache::get(self::COOLDOWN_KEY, 0);

        return max(0, $until - now()->getTimestamp());
    }

    /**
     * @return array{products: int, providers: int, active: int, skipped: int, deactivated: int, created: int, renamed: int}
     */
    public function sync(): array
    {
        $rows = $this->fetchPriceList();

        $margin = (float) config('digiflazz.margin_percent');
        $now = now();
        $skus = [];
        $providers = [];
        $active = 0;
        $skipped = 0;
        $created = 0;
        $renamed = 0;

        // Semua SKU yang dikirim Digiflazz kali ini — dipakai untuk memastikan
        // produk yang kodenya diganti memang sudah tidak ada lagi di supplier.
        $incoming = [];

        foreach ($rows as $row) {
            $sku = trim((string) ($row['buyer_sku_code'] ?? ''));

            if ($sku !== '') {
                $incoming[$sku] = true;
            }
        }

        DB::transaction(function () use ($rows, $incoming, $margin, $now, &$skus, &$providers, &$active, &$skipped, &$created, &$renamed): void {
            [$bySku, $bySellerName] = $this->existingProducts();

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
                $seller = $this->seller((string) ($row['seller_name'] ?? ''));
                $name = trim((string) ($row['product_name'] ?? $sku));

                if ($seller !== null) {
                    $providers[$seller] = true;
                }

                $supplier = [
                    'category' => $category,
                    'operator' => trim((string) ($row['brand'] ?? '')),
                    'name' => $name,
                    // Petunjuk input dari seller (mis. "gabungan user_id dan
                    // zone_id") — ditampilkan di halaman beli.
                    'instruction' => $this->instruction((string) ($row['desc'] ?? '')),
                    'cost_price' => $cost,
                    'seller_name' => $seller,
                    'active' => $enabled,
                    'is_demo' => false,
                    'cost_synced_at' => $now,
                ];

                $existing = $bySku[$sku] ?? null;

                if ($existing === null) {
                    // Seller bisa mengganti kode produk. Bila SKU ini baru tetapi
                    // seller + nama produknya sudah ada dan SKU lamanya hilang dari
                    // daftar, produk itu yang diperbarui (kode mengikuti Digiflazz)
                    // supaya harga jual dan riwayat transaksinya tidak terputus.
                    $renamedFrom = $this->renameCandidate($bySellerName, $seller, $name, $incoming);

                    if ($renamedFrom !== null) {
                        DB::table('products')->where('id', $renamedFrom->id)->update($supplier + ['sku' => $sku]);

                        unset($bySku[$renamedFrom->sku]);
                        $bySku[$sku] = (object) ['id' => $renamedFrom->id, 'sku' => $sku];
                        $renamed++;
                    } else {
                        DB::table('products')->insert($supplier + $this->autoPrices($cost, $margin) + ['sku' => $sku]);
                        $bySku[$sku] = (object) ['id' => null, 'sku' => $sku];
                        $created++;
                    }
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
            'providers' => count($providers),
            'active' => $active,
            'skipped' => $skipped,
            'deactivated' => $deactivated,
            'created' => $created,
            'renamed' => $renamed,
        ];
    }

    /**
     * Ambil pricelist + nyalakan jeda lokal. Batas Digiflazz tetap ditegakkan di
     * sisi mereka, jadi setiap balasan limit ikut menahan tombol admin 5 menit.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchPriceList(): array
    {
        try {
            $rows = $this->client->priceList();
        } catch (\Throwable $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'limit')) {
                $this->startCooldown();
            }

            throw $exception;
        }

        $this->startCooldown();

        return $rows;
    }

    private function startCooldown(): void
    {
        Cache::put(
            self::COOLDOWN_KEY,
            now()->getTimestamp() + self::COOLDOWN_SECONDS,
            self::COOLDOWN_SECONDS + 60,
        );
    }

    /**
     * Produk supplier yang sudah ada, diindeks per SKU dan per seller + nama.
     *
     * @return array{0: array<string, object>, 1: array<string, list<object>>}
     */
    private function existingProducts(): array
    {
        $bySku = [];
        $bySellerName = [];

        $products = DB::table('products')
            ->where('is_demo', false)
            ->get(['id', 'sku', 'name', 'seller_name']);

        foreach ($products as $product) {
            $bySku[$product->sku] = $product;

            if ($product->seller_name !== null) {
                $bySellerName[$this->matchKey($product->seller_name, $product->name)][] = $product;
            }
        }

        return [$bySku, $bySellerName];
    }

    /**
     * Produk lama yang kodenya harus diganti mengikuti Digiflazz: seller + nama
     * sama, SKU lamanya sudah tidak dikirim Digiflazz lagi, dan kandidatnya hanya
     * satu (kalau ambigu, biarkan jadi produk baru).
     *
     * @param  array<string, list<object>>  $bySellerName
     * @param  array<string, bool>  $incoming
     */
    private function renameCandidate(array $bySellerName, ?string $seller, string $name, array $incoming): ?object
    {
        if ($seller === null) {
            return null;
        }

        $candidates = array_values(array_filter(
            $bySellerName[$this->matchKey($seller, $name)] ?? [],
            static fn (object $product): bool => ! isset($incoming[$product->sku]),
        ));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function matchKey(string $seller, string $name): string
    {
        return mb_strtolower(trim($seller)).'|'.mb_strtolower(trim($name));
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
