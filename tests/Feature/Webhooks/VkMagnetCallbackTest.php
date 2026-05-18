<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Jobs\ProcessVkMagnetCallback;
use App\Jobs\SendLeadMagnet;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class VkMagnetCallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'vk-callback-secret';

    private string $confirmation = 'a1b2c3d4';

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'vk_group_screen_name' => 'group',
            'vk_callback_secret' => $this->secret,
            'vk_confirmation_code' => $this->confirmation,
        ]);
    }

    /** @test */
    public function confirmation_request_returns_code_as_plain_text(): void
    {
        $resp = $this->postJson('/api/webhooks/vk-magnet', ['type' => 'confirmation']);

        $resp->assertOk();
        $this->assertSame($this->confirmation, $resp->getContent());
        $this->assertStringContainsString('text/plain', $resp->headers->get('Content-Type'));
    }

    /** @test */
    public function rejects_message_with_wrong_secret(): void
    {
        $this->postJson('/api/webhooks/vk-magnet', [
            'type' => 'message_new',
            'secret' => 'wrong',
            'object' => ['message' => ['from_id' => 1, 'text' => 'hi']],
        ])->assertStatus(403);
    }

    /** @test */
    public function valid_message_returns_plain_ok(): void
    {
        Bus::fake();

        $resp = $this->postJson('/api/webhooks/vk-magnet', [
            'type' => 'message_new',
            'secret' => $this->secret,
            'object' => ['message' => ['from_id' => 1, 'text' => 'hi']],
        ]);

        $resp->assertOk();
        $this->assertSame('ok', $resp->getContent());
        Bus::assertDispatched(ProcessVkMagnetCallback::class);
    }

    /** @test */
    public function payload_with_ref_token_attaches_vk_user_id(): void
    {
        Bus::fake([SendLeadMagnet::class]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true, 'lead_magnet_file_path' => 'm/x.pdf',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'VKTOKEN', 'magnet_channel' => 'vk',
        ]);

        (new ProcessVkMagnetCallback([
            'type' => 'message_new',
            'object' => [
                'message' => [
                    'from_id' => 12345,
                    'payload' => json_encode(['ref' => 'VKTOKEN']),
                ],
            ],
        ]))->handle();

        $this->assertSame(12345, $lead->fresh()->vk_user_id);
        Bus::assertDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function token_in_plain_text_message_works_as_fallback(): void
    {
        Bus::fake([SendLeadMagnet::class]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true, 'lead_magnet_file_path' => 'm/x.pdf',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'PLAINTOKEN12', 'magnet_channel' => 'vk',
        ]);

        (new ProcessVkMagnetCallback([
            'type' => 'message_new',
            'object' => [
                'message' => [
                    'from_id' => 12345,
                    'text' => 'PLAINTOKEN12',
                ],
            ],
        ]))->handle();

        Bus::assertDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function non_message_event_is_ignored(): void
    {
        Bus::fake();

        (new ProcessVkMagnetCallback([
            'type' => 'message_reply',
            'object' => [],
        ]))->handle();

        Bus::assertNotDispatched(SendLeadMagnet::class);
    }
}
