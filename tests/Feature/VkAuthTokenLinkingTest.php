<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * VK-привязка через одноразовый неугадываемый токен (как telegram_auth_token)
 * вместо сырого ?ref={user_id}. Закрывает IDOR: перебором последовательных id
 * больше нельзя привязать свой VK к чужому аккаунту.
 */
class VkAuthTokenLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Вебхук VK теперь fail-closed — задаём секрет и шлём его в теле (см. vkWebhook).
        config(['services.vk.callback_secret' => 'test-vk']);
        config(['services.vk.group_id' => '12345']);
        config(['services.vk.bot_token' => 'test-token']);
        config(['services.telegram.admin_id' => '']);

        // Любые исходящие к VK/Telegram — заглушаем.
        Http::fake(['*' => Http::response(['response' => 1], 200)]);
    }

    private function vkWebhook(int $fromId, ?string $ref, string $text = 'привет'): TestResponse
    {
        $message = ['from_id' => $fromId, 'text' => $text];
        if ($ref !== null) {
            $message['ref'] = $ref;
        }

        return $this->postJson('/api/vk-webhook', [
            'type' => 'message_new',
            'secret' => 'test-vk',
            'object' => ['message' => $message],
        ]);
    }

    /** @test */
    public function connect_post_issues_a_token_and_redirects_with_it(): void
    {
        $user = User::factory()->create(['vk_id' => null, 'vk_auth_token' => null]);

        // H3313: выдача токена переехала с GET на CSRF-защищённый POST.
        $response = $this->actingAs($user)->post(route('vk.connect.start'));

        $user->refresh();
        $this->assertNotNull($user->vk_auth_token);
        $this->assertSame(32, strlen($user->vk_auth_token));

        // Редирект на vk.me несёт токен, а НЕ user id.
        $response->assertRedirect("https://vk.me/club12345?ref={$user->vk_auth_token}");

        // Точное сравнение значения ref, а не substring — 32-символьный случайный
        // токен может случайно СОДЕРЖАТЬ "ref={id}" как подстроку (напр. token
        // начинается с той же цифры, что id), что делало assertStringNotContainsString
        // флаки-тестом (H1355, 20-07-2026).
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame($user->vk_auth_token, $query['ref'] ?? null);
        $this->assertNotSame((string) $user->id, $query['ref'] ?? null);
    }

    /** @test */
    public function webhook_links_account_by_token_and_burns_it(): void
    {
        $user = User::factory()->create(['vk_id' => null, 'vk_auth_token' => 'tok_abc123']);

        $this->vkWebhook(555, 'tok_abc123')->assertOk();

        $user->refresh();
        $this->assertSame(555, (int) $user->vk_id);
        $this->assertNotNull($user->vk_connected_at);
        // Токен одноразовый — погашен после привязки.
        $this->assertNull($user->vk_auth_token);
    }

    /** @test */
    public function webhook_does_not_link_by_raw_user_id_anymore(): void
    {
        // IDOR-кейс: злоумышленник подставляет в ref сырой id жертвы.
        $victim = User::factory()->create(['vk_id' => null, 'vk_auth_token' => null]);

        $this->vkWebhook(666, (string) $victim->id)->assertOk();

        $victim->refresh();
        // Привязки не произошло — id это не токен.
        $this->assertNull($victim->vk_id);
    }

    /** @test */
    public function webhook_does_not_overwrite_an_already_linked_account(): void
    {
        $user = User::factory()->create(['vk_id' => 111, 'vk_auth_token' => 'tok_xyz']);

        $this->vkWebhook(999, 'tok_xyz')->assertOk();

        $user->refresh();
        // vk_id не перезаписан чужим id из тела вебхука.
        $this->assertSame(111, (int) $user->vk_id);
    }

    /** @test */
    public function disconnect_clears_the_auth_token(): void
    {
        $user = User::factory()->create(['vk_id' => 222, 'vk_auth_token' => 'tok_left_over']);

        $this->actingAs($user)
            ->post(route('student.messenger.disconnect', ['channel' => 'vk']))
            ->assertRedirect();

        $user->refresh();
        $this->assertNull($user->vk_id);
        $this->assertNull($user->vk_auth_token);
    }
}
