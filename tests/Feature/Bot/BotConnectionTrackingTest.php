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

    public function test_telegram_binding_captures_username(): void
    {
        $user = User::factory()->create(['telegram_auth_token' => 'tok456', 'telegram_id' => null]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 888010],
                'from' => ['id' => 888010, 'username' => 'VolkovAT'],
                'text' => '/start tok456',
            ],
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(888010, (int) $fresh->telegram_id);
        // Регистр сохраняем как прислал Telegram; ведущий @ не приходит, но мы его срезаем на всякий.
        $this->assertSame('VolkovAT', $fresh->telegram_username);
        $this->assertSame('https://t.me/VolkovAT', $fresh->telegramLink());
    }

    public function test_incoming_message_backfills_username_for_already_linked_user(): void
    {
        // Уже привязанный студент без пойманного @username.
        $user = User::factory()->create(['telegram_id' => 888011, 'telegram_username' => null]);

        // Любое сообщение боту: триггер «куратор» уводит в режим человека и
        // возвращается ДО обращения к ИИ — внешних вызовов нет.
        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 888011],
                'from' => ['id' => 888011, 'username' => 'latename'],
                'text' => 'позови куратора',
            ],
        ])->assertOk();

        $this->assertSame('latename', $user->fresh()->telegram_username);
    }

    public function test_normalize_telegram_username_strips_at_and_blanks(): void
    {
        $this->assertSame('foo', User::normalizeTelegramUsername('@foo'));
        $this->assertSame('foo', User::normalizeTelegramUsername(' foo '));
        $this->assertSame('foo', User::normalizeTelegramUsername('https://t.me/foo/'));
        $this->assertSame('foo', User::normalizeTelegramUsername('t.me/foo'));
        $this->assertNull(User::normalizeTelegramUsername(''));
        $this->assertNull(User::normalizeTelegramUsername(null));
    }

    public function test_vk_binding_records_connected_at(): void
    {
        // Привязка идёт по одноразовому токену (vk_auth_token), а не по сырому
        // user id — это закрытый VK-IDOR (см. VkController::connect / PR #173).
        $user = User::factory()->create(['vk_id' => null, 'vk_auth_token' => 'vktok123']);

        $this->postJson('/api/vk-webhook', [
            'type' => 'message_new',
            'object' => ['message' => ['from_id' => 888002, 'text' => 'привет', 'ref' => 'vktok123']],
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(888002, (int) $fresh->vk_id);
        $this->assertNotNull($fresh->vk_connected_at);
        // Токен одноразовый — гасится сразу после привязки.
        $this->assertNull($fresh->vk_auth_token);
    }
}
