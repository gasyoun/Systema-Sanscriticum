<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ForwardUpdateToN8n;
use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Jobs\SendLeadMagnet;
use App\Models\LandingBot;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LandingBotMagnetTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'super-secret-webhook-token-1234567890';

    private LandingPage $landing;

    private LandingBot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('lead-submit:127.0.0.1');
        Cache::flush();

        $this->landing = LandingPage::create([
            'title' => 'Bot Landing',
            'slug' => 'bot-landing-'.uniqid(),
            'is_active' => true,
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/test.pdf',
            'lead_magnet_title' => 'Тестовый PDF',
            'lead_magnet_caption' => 'Спасибо!',
            'lead_magnet_default_channel' => 'telegram',
        ]);

        $this->bot = LandingBot::create([
            'landing_page_id' => $this->landing->id,
            'is_active' => true,
            'tg_bot_username' => 'landing_specific_bot',
            'tg_bot_token' => 'landing-bot-token',
            'tg_webhook_secret' => self::SECRET,
            'n8n_forward_url' => 'https://n8n.example.com/webhook/abc',
        ]);
    }

    /** @test */
    public function webhook_key_auto_generated(): void
    {
        $this->assertSame(40, strlen($this->bot->webhook_key));
    }

    /** @test */
    public function per_bot_webhook_rejects_wrong_secret(): void
    {
        Bus::fake();

        $this->postJson('/api/webhooks/telegram-magnet/'.$this->bot->webhook_key, ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => 'WRONG',
        ])->assertForbidden();

        Bus::assertNotDispatched(ProcessTelegramMagnetUpdate::class);
    }

    /** @test */
    public function per_bot_webhook_unknown_key_404(): void
    {
        Bus::fake();

        $this->postJson('/api/webhooks/telegram-magnet/nope-no-such-key', ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertNotFound();

        Bus::assertNotDispatched(ProcessTelegramMagnetUpdate::class);
    }

    /** @test */
    public function per_bot_webhook_accepts_and_dispatches_with_bot_id(): void
    {
        Bus::fake();

        $this->postJson('/api/webhooks/telegram-magnet/'.$this->bot->webhook_key, ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk();

        Bus::assertDispatched(
            ProcessTelegramMagnetUpdate::class,
            fn (ProcessTelegramMagnetUpdate $job) => $job->landingBotId === $this->bot->id
        );
    }

    /** @test */
    public function callback_update_is_forwarded_to_n8n_without_magnet(): void
    {
        Bus::fake();

        // callback_query не содержит ['message'] — раньше был ранний return.
        $update = ['update_id' => 2, 'callback_query' => ['id' => 'x', 'data' => 'next']];

        (new ProcessTelegramMagnetUpdate($update, $this->bot->id))->handle();

        Bus::assertDispatched(
            ForwardUpdateToN8n::class,
            fn (ForwardUpdateToN8n $job) => $job->url === 'https://n8n.example.com/webhook/abc'
        );
        Bus::assertNotDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function start_with_token_forwards_to_n8n_and_delivers_magnet(): void
    {
        Bus::fake();

        $lead = Lead::create([
            'landing_page_id' => $this->landing->id,
            'name' => 'Иван',
            'contact' => '+70000000000',
            'email' => 'i@example.com',
            'magnet_token' => 'tok123456789',
            'magnet_channel' => 'telegram',
        ]);

        $update = [
            'update_id' => 3,
            'message' => ['chat' => ['id' => 555], 'text' => '/start tok123456789'],
        ];

        (new ProcessTelegramMagnetUpdate($update, $this->bot->id))->handle();

        Bus::assertDispatched(ForwardUpdateToN8n::class);
        Bus::assertDispatched(SendLeadMagnet::class, fn (SendLeadMagnet $j) => $j->leadId === $lead->id);

        $this->assertSame('555', (string) $lead->fresh()->telegram_chat_id);
    }

    /** @test */
    public function flash_deep_link_uses_landing_bot_username(): void
    {
        // Глобальный telegram НЕ настроен — deep-link всё равно строится по боту лендинга.
        RateLimiter::clear('lead-submit:127.0.0.1');

        $resp = $this->post('/leads/store', [
            'name' => 'Иван',
            'contact' => '+79991234567',
            'email' => 'deep'.uniqid().'@example.com',
            'social' => '@me',
            'landing_page_id' => $this->landing->id,
        ]);

        $lead = Lead::latest('id')->first();

        $resp->assertRedirect()
            ->assertSessionHas('redirect_url', "https://t.me/landing_specific_bot?start={$lead->magnet_token}");
    }

    /** @test */
    public function send_lead_magnet_uses_landing_bot_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        Storage::fake('public');
        Storage::disk('public')->put('magnets/test.pdf', 'PDF-CONTENT');

        $lead = Lead::create([
            'landing_page_id' => $this->landing->id,
            'name' => 'Иван',
            'contact' => '+70000000000',
            'email' => 'send@example.com',
            'magnet_token' => 'tokAAAAAAAAA',
            'magnet_channel' => 'telegram',
            'telegram_chat_id' => 777,
        ]);

        (new SendLeadMagnet($lead->id))->handle(app(DeliveryChannelManager::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/botlanding-bot-token/sendDocument'));

        $this->assertNotNull($lead->fresh()->magnet_delivered_at);
    }
}
