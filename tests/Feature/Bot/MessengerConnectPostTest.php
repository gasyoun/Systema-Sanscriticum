<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3313: выдача токенов привязки TG/VK — только через CSRF-защищённый POST.
 * GET-страницы инструкционные и токен не вращают (анти CSRF-by-navigation:
 * сторонняя страница больше не может заставить браузер перегенерить токен).
 */
class MessengerConnectPostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.vk.group_id' => '12345',
            'services.telegram.bot_username' => 'FallbackBot',
            'services.telegram.student_bot_username' => 'StudentTestBot',
        ]);
    }

    public function test_get_connect_pages_do_not_rotate_tokens(): void
    {
        $user = User::factory()->create(['telegram_auth_token' => null, 'vk_auth_token' => null]);

        $this->actingAs($user)->get(route('telegram.connect'))
            ->assertOk()
            ->assertSee('Открыть Telegram и привязать');

        $this->actingAs($user)->get(route('vk.connect'))
            ->assertOk()
            ->assertSee('Открыть ВКонтакте и привязать');

        $user->refresh();
        $this->assertNull($user->telegram_auth_token);
        $this->assertNull($user->vk_auth_token);
    }

    public function test_get_connect_keeps_existing_tokens_intact(): void
    {
        $user = User::factory()->create(['telegram_auth_token' => 'tg_old', 'vk_auth_token' => 'vk_old']);

        $this->actingAs($user)->get(route('telegram.connect'))->assertOk();
        $this->actingAs($user)->get(route('vk.connect'))->assertOk();

        $user->refresh();
        $this->assertSame('tg_old', $user->telegram_auth_token);
        $this->assertSame('vk_old', $user->vk_auth_token);
    }

    public function test_post_telegram_connect_rotates_token_and_redirects_to_bot(): void
    {
        $user = User::factory()->create(['telegram_auth_token' => 'tg_old']);

        $response = $this->actingAs($user)->post(route('telegram.connect.start'));

        $user->refresh();
        $this->assertNotSame('tg_old', $user->telegram_auth_token);
        $this->assertSame(32, strlen((string) $user->telegram_auth_token));

        // Редирект в бота несёт свежий токен в ?start=
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame($user->telegram_auth_token, $query['start'] ?? null);
        $this->assertStringStartsWith('https://t.me/StudentTestBot?start=', (string) $response->headers->get('Location'));
    }

    public function test_post_vk_connect_rotates_token_and_redirects_to_club(): void
    {
        $user = User::factory()->create(['vk_auth_token' => 'vk_old', 'vk_id' => null]);

        $response = $this->actingAs($user)->post(route('vk.connect.start'));

        $user->refresh();
        $this->assertNotSame('vk_old', $user->vk_auth_token);
        $this->assertSame(32, strlen((string) $user->vk_auth_token));

        // Редирект на vk.me несёт токен в ?ref=, а НЕ user id.
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame($user->vk_auth_token, $query['ref'] ?? null);
        $this->assertStringStartsWith('https://vk.me/club12345?ref=', (string) $response->headers->get('Location'));
    }

    public function test_connect_paths_only_accept_get_and_post(): void
    {
        // GET на /connect легитимен (инструкционная страница), POST выдаёт
        // токен; прочие методы не должны проходить (405).
        $user = User::factory()->create();

        foreach (['put', 'patch', 'delete'] as $method) {
            $this->actingAs($user)->{$method}(route('telegram.connect'))->assertStatus(405);
            $this->actingAs($user)->{$method}(route('vk.connect'))->assertStatus(405);
        }

        $user->refresh();
        $this->assertNull($user->telegram_auth_token);
        $this->assertNull($user->vk_auth_token);
    }

    public function test_guest_cannot_reach_connect_or_start_routes(): void
    {
        $this->get(route('telegram.connect'))->assertRedirect();
        $this->get(route('vk.connect'))->assertRedirect();
        $this->post(route('telegram.connect.start'))->assertRedirect();
        $this->post(route('vk.connect.start'))->assertRedirect();

        $this->assertGuest();
    }
}
