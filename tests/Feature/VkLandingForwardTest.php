<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ForwardUpdateToN8n;
use App\Jobs\ProcessVkMagnetCallback;
use App\Jobs\SendLeadMagnet;
use App\Models\LandingBot;
use App\Models\LandingPage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class VkLandingForwardTest extends TestCase
{
    use RefreshDatabase;

    private const VK_URL = 'https://n8n.example.com/webhook/vk-abc';

    private LandingPage $landing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landing = LandingPage::create([
            'title' => 'VK Landing',
            'slug' => 'vk-landing-'.uniqid(),
            'is_active' => true,
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/test.pdf',
            'lead_magnet_default_channel' => 'vk',
        ]);

        LandingBot::create([
            'landing_page_id' => $this->landing->id,
            'vk_is_active' => true,
            'vk_n8n_forward_url' => self::VK_URL,
        ]);
    }

    private function messageNew(int $fromId, ?string $ref = null, string $text = 'привет'): array
    {
        $message = ['from_id' => $fromId, 'text' => $text];
        if ($ref !== null) {
            $message['ref'] = $ref;
        }

        return ['type' => 'message_new', 'object' => ['message' => $message]];
    }

    /** @test */
    public function ref_message_delivers_magnet_and_forwards_and_sets_vk_id(): void
    {
        Bus::fake();

        $lead = Lead::create([
            'landing_page_id' => $this->landing->id,
            'name' => 'Иван', 'contact' => '+70000000000', 'email' => 'v@example.com',
            'magnet_token' => 'vktok1234567', 'magnet_channel' => 'vk',
        ]);

        (new ProcessVkMagnetCallback($this->messageNew(555, ref: 'vktok1234567')))->handle();

        Bus::assertDispatched(SendLeadMagnet::class, fn (SendLeadMagnet $j) => $j->leadId === $lead->id);
        Bus::assertDispatched(ForwardUpdateToN8n::class, fn (ForwardUpdateToN8n $j) => $j->url === self::VK_URL);
        $this->assertSame('555', (string) $lead->fresh()->vk_user_id);
    }

    /** @test */
    public function survey_message_without_ref_forwards_by_vk_user_id(): void
    {
        Bus::fake();

        Lead::create([
            'landing_page_id' => $this->landing->id,
            'name' => 'Иван', 'contact' => '+70000000000', 'email' => 's@example.com',
            'magnet_token' => 'vktok7777777', 'magnet_channel' => 'vk', 'vk_user_id' => 555,
        ]);

        (new ProcessVkMagnetCallback($this->messageNew(555, text: 'Москва')))->handle();

        Bus::assertDispatched(ForwardUpdateToN8n::class, fn (ForwardUpdateToN8n $j) => $j->url === self::VK_URL);
        Bus::assertNotDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function refless_unknown_user_is_ignored(): void
    {
        Bus::fake();

        (new ProcessVkMagnetCallback($this->messageNew(999, text: 'кто это')))->handle();

        Bus::assertNotDispatched(ForwardUpdateToN8n::class);
        Bus::assertNotDispatched(SendLeadMagnet::class);
    }

    /** @test */
    public function forward_skipped_when_vk_inactive(): void
    {
        Bus::fake();

        $this->landing->bot->update(['vk_is_active' => false]);

        Lead::create([
            'landing_page_id' => $this->landing->id,
            'name' => 'Иван', 'contact' => '+70000000000', 'email' => 'off@example.com',
            'magnet_channel' => 'vk', 'vk_user_id' => 555,
        ]);

        (new ProcessVkMagnetCallback($this->messageNew(555, text: 'тест')))->handle();

        Bus::assertNotDispatched(ForwardUpdateToN8n::class);
    }
}
