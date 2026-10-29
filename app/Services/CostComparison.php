<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Banding harga modal supplier untuk konsol admin.
 *
 * Harga modal Digiflazz sudah tersimpan di tabel `products` (`cost_price`),
 * sedangkan harga TriPay ditarik langsung dari API. Keduanya tidak memakai kode
 * produk yang sama, jadi padanan dicari dari **nama produk yang dinormalkan**
 * (huruf besar, tanpa spasi/tanda baca): "AXIS 5.000" cocok dengan "AXIS 5.000".
 * Produk yang namanya berbeda antar supplier muncul sebagai "tanpa padanan" —
 * itu informasi yang perlu diperiksa admin, bukan kesalahan sinkronisasi.
 */
class CostComparison
{
    public function __construct(private readonly TripayClient $tripay)
    {
    }

    public function isConfigured(): bool
    {
        return $this->tripay->isConfigured();
    }

    /** Kunci padanan nama produk: "TSEL 10.000 RP" → "TSEL10000". */
    public static function nameKey(string $name): string
    {
        $upper = str_replace(['RP', ','], '', strtoupper($name));

        return preg_replace('/[^A-Z0-9]/', '', $upper) ?? '';
    }

    /**
     * @param  list<array<string, mixed>>  $products  produk Digiflazz dari tabel `products`
     * @param  list<array<string, mixed>>  $tripayItems  hasil `TripayClient::products()['items']`
     * @return array{items: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function compare(array $products, array $tripayItems): array
    {
        [$byKey, $tripayKeys] = self::indexTripay($tripayItems);

        $items = [];
        $matchedKeys = [];

        foreach ($products as $product) {
            $key = self::nameKey((string) ($product['name'] ?? ''));
            $match = $key !== '' ? ($byKey[$key] ?? null) : null;

            if ($match !== null) {
                $matchedKeys[$key] = true;
            }

            $cost = (int) ($product['costPrice'] ?? 0);
            $tripayCost = $match['price'] ?? null;
            // Positif = TriPay lebih murah, negatif = Digiflazz lebih murah.
            $diff = $tripayCost === null ? null : $cost - $tripayCost;

            $items[] = $product + [
                'tripaySku' => $match['sku'] ?? null,
                'tripayCost' => $tripayCost,
                'diff' => $diff,
                'cheaper' => $diff === null
                    ? null
                    : ($diff > 0 ? 'tripay' : ($diff < 0 ? 'digiflazz' : 'sama')),
            ];
        }

        $matched = array_values(array_filter($items, fn (array $row) => $row['diff'] !== null));

        $summary = [
            'products' => count($items),
            'matched' => count($matched),
            'unmatched' => count($items) - count($matched),
            'tripayCheaper' => count(array_filter($matched, fn (array $row) => $row['diff'] > 0)),
            'digiflazzCheaper' => count(array_filter($matched, fn (array $row) => $row['diff'] < 0)),
            'same' => count(array_filter($matched, fn (array $row) => $row['diff'] === 0)),
            // Potensi hemat per transaksi bila seluruh selisih positif dipakai.
            'potentialSaving' => (int) array_sum(array_map(fn (array $row) => max(0, (int) $row['diff']), $matched)),
            // Produk TriPay yang tidak punya pasangan di daftar kita.
            'tripayOnly' => count(array_diff_key($tripayKeys, $matchedKeys)),
        ];

        return ['items' => $items, 'summary' => $summary];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, summary: array<string, int>, fetchedAt: string}
     */
    public function report(string $search = '', string $view = 'all', int $limit = 200): array
    {
        $priceList = $this->tripay->products();

        $rows = DB::table('products')
            ->where('is_demo', false)
            ->where('cost_price', '>', 0)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($keyword) use ($search) {
                    $keyword->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('sku', 'ilike', '%'.$search.'%')
                        ->orWhere('operator', 'ilike', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'operator', 'category', 'cost_price', 'price', 'retail_price']);

        $products = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'sku' => $row->sku,
            'name' => $row->name,
            'operator' => $row->operator,
            'category' => $row->category,
            'costPrice' => (int) $row->cost_price,
            'price' => (int) $row->price,
            'retailPrice' => (int) $row->retail_price,
        ])->all();

        ['items' => $items, 'summary' => $summary] = $this->compare($products, $priceList['items']);

        $visible = match ($view) {
            'tripay' => array_values(array_filter($items, fn (array $row) => ($row['diff'] ?? 0) > 0)),
            'digiflazz' => array_values(array_filter($items, fn (array $row) => ($row['diff'] ?? 0) < 0)),
            default => $items,
        };

        // Produk berpasangan dulu (selisih terbesar di atas), lalu yang tanpa
        // padanan supaya daftar teratas langsung berisi peluang penghematan.
        usort($visible, function (array $a, array $b): int {
            if (($a['diff'] === null) !== ($b['diff'] === null)) {
                return $a['diff'] === null ? 1 : -1;
            }

            $byDiff = ($b['diff'] ?? 0) <=> ($a['diff'] ?? 0);

            return $byDiff !== 0 ? $byDiff : strcmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'items' => array_slice($visible, 0, $limit),
            'total' => count($visible),
            'summary' => $summary,
            'fetchedAt' => $priceList['fetchedAt'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tripayItems
     * @return array{0: array<string, array{sku: string, price: int}>, 1: array<string, true>}
     */
    private static function indexTripay(array $tripayItems): array
    {
        $byKey = [];
        $keys = [];

        foreach ($tripayItems as $item) {
            if (empty($item['available']) || (int) ($item['price'] ?? 0) <= 0) {
                continue;
            }

            $key = self::nameKey((string) ($item['name'] ?? ''));

            if ($key === '') {
                continue;
            }

            $keys[$key] = true;

            // Satu nama bisa muncul beberapa kali; pakai harga termurah.
            if (! isset($byKey[$key]) || (int) $item['price'] < $byKey[$key]['price']) {
                $byKey[$key] = ['sku' => (string) ($item['sku'] ?? ''), 'price' => (int) $item['price']];
            }
        }

        return [$byKey, $keys];
    }
}
