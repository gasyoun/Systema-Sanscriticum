<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingBot;
use App\Models\LandingPage;
use App\Models\MarketingSetting;
use App\Support\TelegramWebhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * telegram:webhooks — состояние вебхуков всех ботов на одном экране и
 * перерегистрация одной командой.
 *
 * Ради чего: 22.07.2026 переехал входной узел, кабинетного бота перерегистрировали,
 * а @zapisi_ORSbot остался на умершем адресе и пять дней не получал сообщений.
 * Заметить было нечем — URL вебхука не хранился нигде.
 */
class SyncTelegramWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): void
    {
        config([
            'services.telegram.student_bot_token' => 'CABINET-TOKEN',
            'services.telegram.student_bot_username' => 'samskrtamru_bot',
            'services.telegram.bot_webhook_secret' => 'cabinet-secret',
        ]);

        MarketingSetting::create([
            'tg_bot_username' => 'magnet_bot',
            'tg_bot_token' => 'MAGNET-TOKEN',
            'tg_webhook_secret' => 'magnet-secret',
            'zapisi_bot_username' => 'zapisi_ORSbot',
            'zapisi_bot_token' => 'ZAPISI-TOKEN',
            'zapisi_webhook_secret' => 'zapisi-secret',
        ]);
    }

    /** @param array<string, mixed> $info */
    private function fakeTelegram(array $info = ['url' => '']): void
    {
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => $info]),
            'api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_shows_state_without_registering_anything(): void
    {
        $this->settings();
        $this->fakeTelegram();

        $this->artisan('telegram:webhooks')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/setWebhook'));
    }

    /**
     * Главный тест: ровно эта ситуация и была 22.07 — бот смотрит на умерший
     * входной узел, а снаружи это неотличимо от «в чате тихо».
     */
    public function test_flags_a_bot_registered_on_a_foreign_url(): void
    {
        $this->settings();
        config(['services.telegram_webhook.base_url' => 'https://103.112.71.201']);
        $this->fakeTelegram(['url' => 'https://samskrte.ru:88/api/webhooks/telegram-zapisi']);

        $this->artisan('telegram:webhooks --strict')->assertFailed();
    }

    public function test_set_registers_every_bot_with_its_own_token_and_secret(): void
    {
        $this->settings();
        $this->fakeTelegram();

        $this->artisan('telegram:webhooks --set')->assertSuccessful();

        foreach ([
            ['CABINET-TOKEN', '/api/telegram/webhook', 'cabinet-secret'],
            ['MAGNET-TOKEN', '/api/webhooks/telegram-magnet', 'magnet-secret'],
            ['ZAPISI-TOKEN', '/api/webhooks/telegram-zapisi', 'zapisi-secret'],
        ] as [$token, $path, $secret]) {
            Http::assertSent(fn (Request $request) => str_contains($request->url(), "/bot{$token}/setWebhook")
                && $request['url'] === TelegramWebhooks::url($path)
                && $request['secret_token'] === $secret);
        }
    }

    /** URL берётся из входного узла, а не из APP_URL — регрессия на причину инцидента. */
    public function test_registers_on_the_configured_entry_node(): void
    {
        $this->settings();
        config(['services.telegram_webhook.base_url' => 'https://103.112.71.201']);
        $this->fakeTelegram(['url' => 'https://103.112.71.201/api/webhooks/telegram-zapisi']);

        $this->artisan('telegram:webhooks --set --only=zapisi')->assertSuccessful();

        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/setWebhook')
            || $request['url'] === 'https://103.112.71.201/api/webhooks/telegram-zapisi');
    }

    /**
     * Бот без секрета пропускается: зарегистрировать его — значит получить живой
     * вебхук, который fail-closed middleware отбивает 403 на каждом апдейте.
     * Снаружи это выглядит точно так же, как полное молчание.
     */
    public function test_bot_without_secret_is_skipped_not_registered(): void
    {
        config([
            'services.telegram.student_bot_token' => 'CABINET-TOKEN',
            'services.telegram.bot_webhook_secret' => '',
        ]);
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN', 'zapisi_webhook_secret' => 'zapisi-secret']);
        $this->fakeTelegram();

        $this->artisan('telegram:webhooks --set --only=cabinet')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/setWebhook'));
    }

    public function test_cabinet_falls_back_to_the_main_bot_token(): void
    {
        config([
            'services.telegram.student_bot_token' => null,
            'services.telegram.bot_token' => 'MAIN-TOKEN',
            'services.telegram.bot_webhook_secret' => 'cabinet-secret',
        ]);
        $this->fakeTelegram();

        $this->artisan('telegram:webhooks --set --only=cabinet')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/botMAIN-TOKEN/setWebhook'));
    }

    public function test_inactive_landing_bots_are_not_touched(): void
    {
        $this->settings();
        $landing = LandingPage::create(['title' => 'Лендинг', 'slug' => 'lending-'.uniqid(), 'content' => []]);
        LandingBot::create([
            'landing_page_id' => $landing->id,
            'tg_bot_username' => 'off_bot',
            'tg_bot_token' => 'OFF-TOKEN',
            'tg_webhook_secret' => 'off-secret',
            'is_active' => false,
        ]);
        $this->fakeTelegram();

        $this->artisan('telegram:webhooks --set')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/botOFF-TOKEN/'));
    }
}
