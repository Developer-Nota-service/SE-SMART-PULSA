<?php

namespace Tests\Feature;

use App\Services\DigiflazzClient;
use App\Services\IntegrationSettings;
use App\Services\MidtransService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductionIntegrationModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn () => 'production');
    }

    public function test_digiflazz_never_sends_development_flag_from_production(): void
    {
        config([
            'digiflazz.username' => 'buyer',
            'digiflazz.api_key' => 'test-api-key',
            'digiflazz.url' => 'https://api.digiflazz.com',
            'digiflazz.testing' => true,
        ]);

        Http::fake([
            'https://api.digiflazz.com/v1/transaction' => Http::response([
                'data' => ['status' => 'Pending'],
            ]),
        ]);

        app(DigiflazzClient::class)->topup('ORDER-1', 'SKU-1', '081234567890');

        Http::assertSent(fn (ClientRequest $request) =>
            $request->url() === 'https://api.digiflazz.com/v1/transaction'
            && ! array_key_exists('testing', $request->data())
        );
    }

    public function test_midtrans_amount_comparison_is_exact_and_rejects_fractional_cents(): void
    {
        $this->assertTrue(MidtransPaymentStatus::amountMatches('2000000.00', 2000000));
        $this->assertTrue(MidtransPaymentStatus::amountMatches('50000.0', 50000));
        $this->assertFalse(MidtransPaymentStatus::amountMatches('2000000.01', 2000000));
        $this->assertFalse(MidtransPaymentStatus::amountMatches('2e6', 2000000));
    }

    public function test_production_rejects_midtrans_sandbox_credentials(): void
    {
        config([
            'services.midtrans.server_key' => 'SB-Mid-server-sandbox-key',
            'services.midtrans.is_production' => false,
        ]);

        $this->assertFalse(app(MidtransService::class)->isConfigured());
    }

    public function test_production_rejects_enabling_digiflazz_development_mode(): void
    {
        $this->expectException(ValidationException::class);

        app(IntegrationSettings::class)->update([
            'DIGIFLAZZ_TESTING' => '1',
        ], 1);
    }

    public function test_production_rejects_disabling_midtrans_live_mode(): void
    {
        $this->expectException(ValidationException::class);

        app(IntegrationSettings::class)->update([
            'MIDTRANS_IS_PRODUCTION' => '0',
        ], 1);
    }
}
