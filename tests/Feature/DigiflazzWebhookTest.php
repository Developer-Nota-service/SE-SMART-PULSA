<?php

namespace Tests\Feature;

use Tests\TestCase;

class DigiflazzWebhookTest extends TestCase
{
    public function test_webhook_is_disabled_without_a_secret(): void
    {
        config(['digiflazz.webhook_secret' => '']);

        $this->postJson('/api/v1/digiflazz/webhook', ['data' => ['ref_id' => 'TRX-1']])
            ->assertStatus(503);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        config(['digiflazz.webhook_secret' => 'rahasia-uji']);

        $this->postJson('/api/v1/digiflazz/webhook', ['data' => ['ref_id' => 'TRX-1']], [
            'X-Hub-Signature' => 'sha1=salah',
        ])->assertStatus(401);
    }

    public function test_webhook_answers_ping_with_pong_when_signed(): void
    {
        $secret = 'rahasia-uji';
        config(['digiflazz.webhook_secret' => $secret]);

        $body = json_encode(['data' => ['ref_id' => 'TRX-1']], JSON_UNESCAPED_SLASHES);
        $signature = 'sha1='.hash_hmac('sha1', $body, $secret);

        $this->call('POST', '/api/v1/digiflazz/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE' => $signature,
            'HTTP_X_DIGIFLAZZ_EVENT' => 'ping',
        ], $body)->assertOk()->assertJson(['message' => 'pong']);
    }
}
