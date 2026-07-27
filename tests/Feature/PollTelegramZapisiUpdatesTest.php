<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * zapisi:poll — приём апдейтов @zapisi_ORSbot через long polling.
 *
 * Заменяет вебхук там, где Telegram не может достучаться до сервера (27.07.2026:
 * входящие из его подсетей не проходят при живом сайте). Апдейты уходят в тот же
 * ProcessTelegramZapisiUpdate, что и с вебхука, — дорожка хранения одна.
 */
class PollTelegramZapisiUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function enableBot(): void
    {
        config(['features.telegram_zapisi_bot' => true]);
        MarketingSetting::create([
            'zapisi_bot_username' => 'zapisi_ORSbot',
            'zapisi_bot_token' => 'BOT-TOKEN-123',
        ]);
    }

    /** @param array<int, array<string, mixed>> $updates */
    private function fakeTelegram(array $updates, string $webhookUrl = ''): void
    {
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => $webhookUrl]]),
            'api.telegram.org/*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => $updates]),
        ]);
    }

    public function test_updates_are_dispatched_to_the_same_job_as_the_webhook(): void
    {
        $this->enableBot();
        Bus::fake();
        $this->fakeTelegram([
            ['update_id' => 700, 'message' => ['message_id' => 1, 'chat' => ['id' => -100111], 'date' => 1785000000, 'text' => 'Привет']],
            ['update_id' => 701, 'message' => ['message_id' => 2, 'chat' => ['id' => -100111], 'date' => 1785000100, 'text' => 'И ещё']],
        ]);

        $this->artisan('zapisi:poll --once')->assertSuccessful();

        Bus::assertDispatchedTimes(ProcessTelegramZapisiUpdate::class, 2);
    }

    /** Курсор двигается, иначе следующий заход получит те же апдейты снова. */
    public function test_offset_advances_past_the_last_update(): void
    {
        $this->enableBot();
        Bus::fake();
        $this->fakeTelegram([
            ['update_id' => 700, 'message' => ['message_id' => 1, 'chat' => ['id' => -100111], 'date' => 1785000000, 'text' => 'Привет']],
            ['update_id' => 705, 'message' => ['message_id' => 2, 'chat' => ['id' => -100111], 'date' => 1785000100, 'text' => 'И ещё']],
        ]);

        $this->artisan('zapisi:poll --once')->assertSuccessful();

        $this->assertSame(706, (int) Cache::get('zapisi:poll:offset'));
    }

    public function test_sends_stored_offset_to_telegram(): void
    {
        $this->enableBot();
        Bus::fake();
        Cache::forever('zapisi:poll:offset', 900);
        $this->fakeTelegram([]);

        $this->artisan('zapisi:poll --once')->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/getUpdates')
                && (int) $request['offset'] === 900;
        });
    }

    /**
     * Пока вебхук зарегистрирован, getUpdates отвечает 409 Conflict — Telegram
     * отдаёт апдейты ровно одним способом, поэтому вебхук снимается явно.
     */
    public function test_existing_webhook_is_removed_before_polling(): void
    {
        $this->enableBot();
        Bus::fake();
        $this->fakeTelegram([], 'https://samskrte.ru/api/webhooks/telegram-zapisi');

        $this->artisan('zapisi:poll --once')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/deleteWebhook'));
    }

    /** Вебхука нет — лишний вызов deleteWebhook на каждом рестарте не нужен. */
    public function test_no_webhook_no_delete_call(): void
    {
        $this->enableBot();
        Bus::fake();
        $this->fakeTelegram([]);

        $this->artisan('zapisi:poll --once')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/deleteWebhook'));
    }

    public function test_disabled_track_is_a_silent_noop(): void
    {
        config(['features.telegram_zapisi_bot' => false]);
        MarketingSetting::create(['zapisi_bot_token' => 'BOT-TOKEN-123']);
        Http::fake();

        $this->artisan('zapisi:poll --once')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_missing_token_fails_loudly_without_calling_telegram(): void
    {
        config(['features.telegram_zapisi_bot' => true]);
        MarketingSetting::create(['zapisi_bot_username' => 'zapisi_ORSbot']);
        Http::fake();

        $this->artisan('zapisi:poll --once')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_malformed_update_without_id_is_skipped(): void
    {
        $this->enableBot();
        Bus::fake();
        $this->fakeTelegram([['message' => ['text' => 'без update_id']]]);

        $this->artisan('zapisi:poll --once')->assertSuccessful();

        Bus::assertNotDispatched(ProcessTelegramZapisiUpdate::class);
        $this->assertNull(Cache::get('zapisi:poll:offset'));
    }
}
