<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TelegramZapisiWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'zapisi-super-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'zapisi_bot_username' => 'zapisi_ORSbot',
            'zapisi_bot_token' => 'fake-token',
            'zapisi_webhook_secret' => $this->secret,
        ]);
    }

    /** @test */
    public function rejects_request_without_secret_header(): void
    {
        $this->postJson('/api/webhooks/telegram-zapisi', [
            'message' => ['chat' => ['id' => -100], 'text' => 'привет'],
        ])->assertStatus(403);
    }

    /** @test */
    public function rejects_request_with_wrong_secret(): void
    {
        $this->postJson('/api/webhooks/telegram-zapisi', [
            'message' => ['chat' => ['id' => -100], 'text' => 'привет'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertStatus(403);
    }

    /** @test */
    public function rejects_when_no_secret_configured(): void
    {
        MarketingSetting::query()->update(['zapisi_webhook_secret' => null]);
        MarketingSetting::flushCached();

        $this->postJson('/api/webhooks/telegram-zapisi', [
            'message' => ['chat' => ['id' => -100], 'text' => 'привет'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertStatus(403);
    }

    /** @test */
    public function accepts_request_with_correct_secret_and_dispatches_job(): void
    {
        Bus::fake();

        $this->postJson('/api/webhooks/telegram-zapisi', [
            'message' => ['chat' => ['id' => -100], 'text' => 'занятие сегодня'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => $this->secret])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertDispatched(ProcessTelegramZapisiUpdate::class);
    }
}
