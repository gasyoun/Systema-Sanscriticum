<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * H5046 — remediation lead-duplicate-flash-magnet-token-disclosure.
 *
 * До фикса дубликат-ветка возвращала status_connect_links и
 * duplicate_deep_link, построенные из magnet_token СУЩЕСТВУЮЩЕГО лида:
 * любой, повторно указавший email жертвы в форме, получал рабочие диплинки
 * с её токеном (получить магнит, привязать свой чат к её заявке).
 * Регрессия: дубликат-флэш generic — ни одного ключа с токеном, а сам
 * magnet_token при повторной заявке ротируется.
 */
class LeadDuplicateTokenDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private LandingPage $landing;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('lead-submit:127.0.0.1');
        Cache::flush();

        MarketingSetting::create([
            'magnet_delivery_mode' => 'redirect',
            'tg_bot_username' => 'test_magnet_bot',
            'tg_bot_token' => 'fake-tg-token',
            'vk_group_screen_name' => 'test_group',
            'vk_access_token' => 'fake-vk-token',
        ]);

        $this->landing = LandingPage::create([
            'title' => 'Магнит-лендинг',
            'slug' => 'disclosure-'.uniqid(),
            'is_active' => true,
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/test.pdf',
            'lead_magnet_title' => 'Тестовый PDF',
            'lead_magnet_default_channel' => 'telegram',
        ]);
    }

    private function postLead(string $email): void
    {
        RateLimiter::clear('lead-submit:127.0.0.1');

        $this->post('/leads/store', [
            'contact' => $email,
            'email' => $email,
            'landing_page_id' => $this->landing->id,
        ])->assertRedirect();
    }

    /** @test */
    public function duplicate_store_flash_contains_no_token_and_rotates_token(): void
    {
        $this->postLead('victim@example.com');

        $lead = Lead::where('email', 'victim@example.com')->firstOrFail();
        $victimToken = $lead->magnet_token;
        $this->assertNotNull($victimToken);

        // Атакующий (или забывчивый лид) повторно шлёт тот же email.
        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->post('/leads/store', [
            'contact' => 'victim@example.com',
            'email' => 'victim@example.com',
            'landing_page_id' => $this->landing->id,
        ])->assertRedirect()
            ->assertSessionHas('is_duplicate', true)
            // Ни одного токен-содержащего ключа во флэше — регрессия H5046.
            ->assertSessionMissing('duplicate_channel')
            ->assertSessionMissing('duplicate_deep_link')
            ->assertSessionMissing('status_connect_links')
            ->assertSessionMissing('magnet_deep_links')
            ->assertSessionMissing('redirect_url');

        // Токен ротирован: старые (возможно утечённые) диплинки мертвы.
        $lead->refresh();
        $this->assertNotSame($victimToken, $lead->magnet_token, 'Повторная заявка должна ротировать magnet_token');
        $this->assertSame(12, strlen($lead->magnet_token));
        // Канал переживает ротацию — вебхуки и новые выдачи строятся от него.
        $this->assertSame('telegram', $lead->magnet_channel);
    }

    /** @test */
    public function duplicate_store_on_status_block_landing_hides_connect_links(): void
    {
        $landing = LandingPage::create([
            'title' => 'Статус-лендинг',
            'slug' => 'status-disclosure-'.uniqid(),
            'is_active' => true,
            'content' => [['type' => 'status_block', 'data' => ['course_family' => 'kashmir-family']]],
        ]);

        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->post('/leads/store', [
            'contact' => 'status-victim@example.com',
            'email' => 'status-victim@example.com',
            'landing_page_id' => $landing->id,
        ])->assertRedirect();

        $lead = Lead::where('email', 'status-victim@example.com')->firstOrFail();
        $tokenBefore = $lead->magnet_token;

        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->post('/leads/store', [
            'contact' => 'status-victim@example.com',
            'email' => 'status-victim@example.com',
            'landing_page_id' => $landing->id,
        ])->assertRedirect()
            ->assertSessionHas('is_duplicate', true)
            ->assertSessionMissing('status_connect_links')
            ->assertSessionMissing('duplicate_deep_link');

        $lead->refresh();
        $this->assertNotNull($lead->magnet_token);
        $this->assertNotSame($tokenBefore, $lead->magnet_token);
    }

    /** @test */
    public function duplicate_store_for_tokenless_lead_still_leaks_nothing(): void
    {
        // Лид без binding (лендинг без магнита и status_block) — повторная
        // заявка не должна ни выдать ссылки, ни упасть.
        $plain = LandingPage::create([
            'title' => 'Обычный',
            'slug' => 'plain-disclosure-'.uniqid(),
            'is_active' => true,
        ]);

        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->post('/leads/store', [
            'contact' => 'plain@example.com',
            'email' => 'plain@example.com',
            'landing_page_id' => $plain->id,
        ])->assertRedirect();

        RateLimiter::clear('lead-submit:127.0.0.1');
        $this->post('/leads/store', [
            'contact' => 'plain@example.com',
            'email' => 'plain@example.com',
            'landing_page_id' => $plain->id,
        ])->assertRedirect()
            ->assertSessionHas('is_duplicate', true)
            ->assertSessionMissing('status_connect_links')
            ->assertSessionMissing('duplicate_channel')
            ->assertSessionMissing('duplicate_deep_link');

        $this->assertNull(Lead::where('email', 'plain@example.com')->firstOrFail()->magnet_token);
    }
}
