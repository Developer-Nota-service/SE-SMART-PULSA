<?php

namespace Tests\Unit;

use App\Services\CostComparison;
use App\Services\TripayClient;
use PHPUnit\Framework\TestCase;

/**
 * Padanan harga modal Digiflazz ↔ TriPay murni soal nama produk; tidak perlu
 * database maupun HTTP, jadi diuji tanpa bootstrap Laravel.
 */
class CostComparisonTest extends TestCase
{
    private function comparison(): CostComparison
    {
        return new CostComparison(new TripayClient());
    }

    public function test_name_key_ignores_spacing_punctuation_and_rupiah_suffix(): void
    {
        $this->assertSame('AXIS5000', CostComparison::nameKey('AXIS 5.000'));
        $this->assertSame('AXIS5000', CostComparison::nameKey('axis 5,000'));
        $this->assertSame('TELKOMSEL10000', CostComparison::nameKey('TELKOMSEL 10.000 RP'));
    }

    public function test_it_pairs_products_by_normalized_name_and_reports_summary(): void
    {
        $products = [
            ['id' => 1, 'name' => 'AXIS 5.000', 'costPrice' => 6200],
            ['id' => 2, 'name' => 'TELKOMSEL 10.000', 'costPrice' => 10500],
            ['id' => 3, 'name' => 'PRODUK TANPA PADANAN', 'costPrice' => 1000],
        ];

        $tripay = [
            ['sku' => 'AX5', 'name' => 'AXIS 5.000', 'price' => 6088, 'available' => true],
            ['sku' => 'TSEL10', 'name' => 'TELKOMSEL 10.000', 'price' => 10800, 'available' => true],
            ['sku' => 'XYZ', 'name' => 'PRODUK LAIN', 'price' => 900, 'available' => true],
        ];

        ['items' => $items, 'summary' => $summary] = $this->comparison()->compare($products, $tripay);

        $this->assertSame(112, $items[0]['diff']);
        $this->assertSame('tripay', $items[0]['cheaper']);
        $this->assertSame('AX5', $items[0]['tripaySku']);

        $this->assertSame(-300, $items[1]['diff']);
        $this->assertSame('digiflazz', $items[1]['cheaper']);

        $this->assertNull($items[2]['diff']);
        $this->assertNull($items[2]['cheaper']);
        $this->assertNull($items[2]['tripayCost']);

        $this->assertSame(3, $summary['products']);
        $this->assertSame(2, $summary['matched']);
        $this->assertSame(1, $summary['unmatched']);
        $this->assertSame(1, $summary['tripayCheaper']);
        $this->assertSame(1, $summary['digiflazzCheaper']);
        $this->assertSame(0, $summary['same']);
        $this->assertSame(112, $summary['potentialSaving']);
        $this->assertSame(1, $summary['tripayOnly']);
    }

    public function test_unavailable_tripay_products_are_ignored_and_duplicates_use_cheapest(): void
    {
        $products = [['id' => 1, 'name' => 'AXIS 10.000', 'costPrice' => 11000]];

        $tripay = [
            // Termurah tapi sedang gangguan — tidak boleh dipakai.
            ['sku' => 'AX10-OFF', 'name' => 'AXIS 10.000', 'price' => 9000, 'available' => false],
            ['sku' => 'AX10-B', 'name' => 'AXIS 10.000', 'price' => 10900, 'available' => true],
            ['sku' => 'AX10-A', 'name' => 'AXIS 10.000', 'price' => 10800, 'available' => true],
        ];

        ['items' => $items] = $this->comparison()->compare($products, $tripay);

        $this->assertSame(10800, $items[0]['tripayCost']);
        $this->assertSame('AX10-A', $items[0]['tripaySku']);
        $this->assertSame(200, $items[0]['diff']);
    }
}
