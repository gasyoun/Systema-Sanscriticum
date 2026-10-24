<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Вебхуки мессенджеров с подписью не попадают под общий api-лимит 60/мин.
 * Прецедент: 07.10 и 13.10.2026 массовая рассылка ВК дала ~2000 событий в
 * минуту на /api/vk-webhook и /api/webhooks/vk-magnet, лимит отбил ~15 тыс.
 * событий ответом 429.
 *
 * Запросы без подписи — ровно то, что прислал бы флуд: подпись их отбивает
 * (не 2xx), но ни один не должен упереться в лимит (429).
 */
class SignedWebhookThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_vk_webhooks_are_not_rate_limited_by_the_api_limiter(): void
    {
        foreach (['/api/vk-webhook', '/api/webhooks/vk-magnet'] as $uri) {
            for ($i = 0; $i < 75; $i++) {
                $status = $this->postJson($uri, ['type' => 'message_new', 'group_id' => 1])->status();
                $this->assertNotSame(429, $status, "{$uri}: запрос #{$i} упёрся в api-лимит");
            }
        }
    }

    public function test_other_api_routes_keep_the_60_per_minute_limit(): void
    {
        $statuses = [];
        for ($i = 0; $i < 61; $i++) {
            $statuses[] = $this->postJson('/api/webhooks/tochka', [])->status();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 60));
        $this->assertSame(429, end($statuses), '61-й запрос к обычному api-маршруту должен получить 429');
    }

    public function test_the_exempt_list_matches_signed_routes_only(): void
    {
        $this->assertNotContains('api/webhooks/tochka', RouteServiceProvider::SIGNED_MESSENGER_WEBHOOKS);
        $this->assertNotContains('api/webhooks/paypal-subscriptions', RouteServiceProvider::SIGNED_MESSENGER_WEBHOOKS);
        $this->assertNotContains('api/webhooks/zoom', RouteServiceProvider::SIGNED_MESSENGER_WEBHOOKS);
    }
}
