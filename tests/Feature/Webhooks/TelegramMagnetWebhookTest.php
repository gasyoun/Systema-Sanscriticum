<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Jobs\SendLeadMagnet;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TelegramMagnetWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'super-secret-tg-webhook-key';

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'bot',
            'tg_bot_token' => 'fake-token',
            'tg_webhook_secret' => $this->secret,
        ]);
    }

    /** @test */
    public function rejects_request_without_secret_header(): void
    {
        $this->postJson('/api/webhooks/telegram-magnet', [
            'message' => ['chat' => ['id' => 1], 'text' => '/start ABC'],
        ])->assertStatus(403);
    }

    /** @test */
    public function rejects_request_with_wrong_secret(): void
    {
        $this->postJson('/api/webhooks/telegram-magnet', [
            'message' => ['chat' => ['id' => 1], 'text' => '/start ABC'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertStatus(403);
    }

    /** @test */
    public function accepts_request_with_correct_secret_and_dispatches_job(): void
    {
        Bus::fake();

        $this->postJson('/api/webhooks/telegram-magnet', [
            'message' => ['chat' => ['id' => 999], 'text' => '/start TOKEN123'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => $this->secret])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertDispatched(ProcessTelegramMagnetUpdate::class);
    }

    /** @test */
    public function start_with_token_attaches_chat_id_and_dispatches_send(): void
    {
        Bus::fake([SendLeadMagnet::class]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/x.pdf',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'KNOWN_TOKEN', 'magnet_channel' => 'telegram',
        ]);

        // Прогоняем Job напрямую — он диспатчится из контроллера через очередь sync,
        // но Bus::fake() блокирует его исполнение. Поэтому handle() зовём руками.
        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 555444333], 'text' => '/start KNOWN_TOKEN'],
        ]))->handle();

        $this->assertSame(555444333, $lead->fresh()->telegram_chat_id);
        Bus::assertDispatched(SendLeadMagnet::class, fn ($job) => $job->leadId === $lead->id);
    }

    /** @test */
    public function start_without_token_does_not_crash(): void
    {
        Bus::fake();
        // Не должно бросать исключений
        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 1], 'text' => '/start'],
        ]))->handle();
        Bus::assertNotDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function unknown_token_logs_and_skips(): void
    {
        Bus::fake();
        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 1], 'text' => '/start NOPE'],
        ]))->handle();
        Bus::assertNotDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function non_start_message_is_ignored(): void
    {
        Bus::fake();
        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 1], 'text' => 'Hello, bot!'],
        ]))->handle();
        Bus::assertNotDispatched(SendLeadMagnet::class);
    }
}
