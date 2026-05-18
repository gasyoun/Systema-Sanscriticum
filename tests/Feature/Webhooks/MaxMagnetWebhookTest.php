<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Jobs\ProcessMaxMagnetUpdate;
use App\Jobs\SendLeadMagnet;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MaxMagnetWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'max-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'max_bot_username' => 'maxbot',
            'max_bot_token' => 'fake-max',
            'max_webhook_secret' => $this->secret,
        ]);
    }

    /** @test */
    public function wrong_secret_in_url_returns_403(): void
    {
        $this->postJson('/api/webhooks/max-magnet/wrong-secret', [
            'update_type' => 'message_created',
        ])->assertStatus(403);
    }

    /** @test */
    public function correct_secret_dispatches_job(): void
    {
        Bus::fake();

        $this->postJson("/api/webhooks/max-magnet/{$this->secret}", [
            'update_type' => 'message_created',
            'message' => ['sender' => ['user_id' => 100], 'body' => ['text' => '/start ABC']],
        ])->assertOk();

        Bus::assertDispatched(ProcessMaxMagnetUpdate::class);
    }

    /** @test */
    public function start_with_token_attaches_max_user_id(): void
    {
        Bus::fake([SendLeadMagnet::class]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true, 'lead_magnet_file_path' => 'm/x.pdf',
        ]);
        // Токен должен быть 12-16 символов (regex в ProcessMaxMagnetUpdate)
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'MAXTOKEN1234', 'magnet_channel' => 'max',
        ]);

        (new ProcessMaxMagnetUpdate([
            'update_type' => 'message_created',
            'message' => [
                'sender' => ['user_id' => '9876543210'],
                'body' => ['text' => '/start MAXTOKEN1234'],
            ],
        ]))->handle();

        $this->assertSame('9876543210', $lead->fresh()->max_user_id);
        Bus::assertDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function token_as_bare_text_works(): void
    {
        Bus::fake([SendLeadMagnet::class]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true, 'lead_magnet_file_path' => 'm/x.pdf',
        ]);
        Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'BAREMAXTOKEN', 'magnet_channel' => 'max',
        ]);

        (new ProcessMaxMagnetUpdate([
            'update_type' => 'message_created',
            'message' => [
                'sender' => ['user_id' => '777'],
                'body' => ['text' => 'BAREMAXTOKEN'],
            ],
        ]))->handle();

        Bus::assertDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function non_message_created_event_is_ignored(): void
    {
        Bus::fake();
        (new ProcessMaxMagnetUpdate([
            'update_type' => 'message_edited',
            'message' => ['sender' => ['user_id' => '1']],
        ]))->handle();
        Bus::assertNotDispatched(SendLeadMagnet::class);
    }
}
