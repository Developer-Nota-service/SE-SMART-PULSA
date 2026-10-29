<?php

namespace Tests\Unit;

use App\Support\ProductAvailability;
use PHPUnit\Framework\TestCase;

class ProductAvailabilityTest extends TestCase
{
    private function product(int $cost, int $max): object
    {
        return (object) ['cost_price' => $cost, 'max_cost_price' => $max];
    }

    public function test_product_is_disturbed_when_cost_exceeds_the_limit(): void
    {
        $this->assertTrue(ProductAvailability::isDisturbed($this->product(11_000, 10_000)));
    }

    public function test_product_is_available_at_or_below_the_limit(): void
    {
        $this->assertFalse(ProductAvailability::isDisturbed($this->product(10_000, 10_000)));
        $this->assertFalse(ProductAvailability::isDisturbed($this->product(9_000, 10_000)));
    }

    public function test_product_without_cost_or_limit_is_available(): void
    {
        $this->assertFalse(ProductAvailability::isDisturbed($this->product(0, 10_000)));
        $this->assertFalse(ProductAvailability::isDisturbed($this->product(11_000, 0)));
    }
}
