<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotConnectionTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);
    }

    public function test_telegram_binding_records_connected_at(): void
    {
        $user = User::factory()->create(['telegram_auth_token' => 'tok123', 'telegram_id' => null]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 888001], 'text' => '/start tok123'],
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(888001, (int) $fresh->telegram_id);
        $this->assertNotNull($fresh->telegram_connected_at);
    }

    public function test_vk_binding_records_connected_at(): void
    {
        $user = User::factory()->create(['vk_id' => null]);

        $this->postJson('/api/vk-webhook', [
            'type' => 'message_new',
            'object' => ['message' => ['from_id' => 888002, 'text' => 'привет', 'ref' => (string) $user->id]],
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(888002, (int) $fresh->vk_id);
        $this->assertNotNull($fresh->vk_connected_at);
    }
}
