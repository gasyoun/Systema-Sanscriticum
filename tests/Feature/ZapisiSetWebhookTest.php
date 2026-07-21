<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * zapisi:set-webhook — регистрация вебхука @zapisi_ORSbot в Telegram по
 * token/secret из MarketingSetting. Middleware входящего роута fail-closed по
 * secret_token, поэтому важно, что регистрируем ИМЕННО zapisi_webhook_secret.
 */
class ZapisiSetWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_webhook_with_zapisi_token_and_secret(): void
    {
        config(['app.url' => 'https://samskrte.ru']);
        MarketingSetting::create([
            'zapisi_bot_username' => 'zapisi_ORSbot',
            'zapisi_bot_token' => 'BOT-TOKEN-123',
            'zapisi_webhook_secret' => 'super-secret-48',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->artisan('zapisi:set-webhook')->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/botBOT-TOKEN-123/setWebhook')
                && $request['url'] === 'https://samskrte.ru/api/webhooks/telegram-zapisi'
                && $request['secret_token'] === 'super-secret-48'
                && in_array('message', (array) $request['allowed_updates'], true)
                && in_array('channel_post', (array) $request['allowed_updates'], true);
        });
    }

    public function test_fails_and_sends_nothing_when_credentials_missing(): void
    {
        MarketingSetting::create([
            'zapisi_bot_username' => 'zapisi_ORSbot',
            // zapisi_bot_token / zapisi_webhook_secret не заданы
        ]);
        Http::fake();

        $this->artisan('zapisi:set-webhook')->assertFailed();

        Http::assertNothingSent();
    }
}
