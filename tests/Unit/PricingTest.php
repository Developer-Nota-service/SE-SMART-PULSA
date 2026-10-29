<?php

namespace Tests\Unit;

use App\Support\Pricing;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    public function test_reseller_pays_the_balance_price(): void
    {
        $this->assertSame(10_000, Pricing::unitPrice(10_000, 10_500, 'reseller'));
    }

    public function test_personal_account_pays_the_public_price(): void
    {
        $this->assertSame(10_500, Pricing::unitPrice(10_000, 10_500, 'personal'));
    }

    public function test_unknown_account_type_falls_back_to_balance_price(): void
    {
        $this->assertSame(10_000, Pricing::unitPrice(10_000, 10_500, 'lainnya'));
    }

    public function test_missing_public_price_falls_back_to_balance_price(): void
    {
        $this->assertSame(10_000, Pricing::unitPrice(10_000, 0, 'personal'));
    }
}
