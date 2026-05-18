<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LeadMagnetFlowTest extends TestCase
{
    use RefreshDatabase;

    private LandingPage $landing;

    protected function setUp(): void
    {
        parent::setUp();

        // Сбрасываем rate-limiter между тестами, иначе второй POST с того же IP получит 429.
        RateLimiter::clear('lead-submit:127.0.0.1');
        Cache::flush();

        MarketingSetting::create([
            'magnet_delivery_mode' => 'redirect',
            'tg_bot_username' => 'test_magnet_bot',
            'tg_bot_token' => 'fake-tg-token',
            'vk_group_screen_name' => 'test_group',
            'max_bot_username' => 'test_max_bot',
            'max_bot_token' => 'fake-max-token',
        ]);

        $this->landing = LandingPage::create([
            'title' => 'Test Magnet Landing',
            'slug' => 'test-magnet-'.uniqid(),
            'is_active' => true,
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/test.pdf',
            'lead_magnet_title' => 'Тестовый PDF',
            'lead_magnet_caption' => 'Спасибо за заявку!',
            'lead_magnet_default_channel' => 'telegram',
        ]);
    }

    private function postLead(array $overrides = [])
    {
        RateLimiter::clear('lead-submit:127.0.0.1');

        return $this->post('/leads/store', array_merge([
            'name' => 'Иван',
            'contact' => '+79991234567',
            'email' => 'test'.uniqid().'@example.com',
            'social' => null,
            'landing_page_id' => $this->landing->id,
        ], $overrides));
    }

    /** @test */
    public function magnet_token_and_channel_attached_when_landing_has_magnet(): void
    {
        $this->postLead(['social' => '@my_telegram'])->assertRedirect();

        $lead = Lead::latest('id')->first();
        $this->assertNotNull($lead->magnet_token);
        $this->assertSame(12, strlen($lead->magnet_token));
        $this->assertSame('telegram', $lead->magnet_channel);
        $this->assertNull($lead->magnet_delivered_at, 'Должен быть NULL до фактической доставки');
    }

    /** @test */
    public function vk_social_routes_to_vk_channel(): void
    {
        $this->postLead(['social' => 'vk.com/durov']);

        $this->assertSame('vk', Lead::latest('id')->first()->magnet_channel);
    }

    /** @test */
    public function max_social_routes_to_max_channel(): void
    {
        $this->postLead(['social' => 'max.ru/@someone']);

        $this->assertSame('max', Lead::latest('id')->first()->magnet_channel);
    }

    /** @test */
    public function empty_social_falls_back_to_landing_default(): void
    {
        $this->postLead(['social' => null]);

        $this->assertSame('telegram', Lead::latest('id')->first()->magnet_channel);
    }

    /** @test */
    public function instagram_social_falls_back_to_landing_default(): void
    {
        $this->postLead(['social' => 'instagram.com/durov']);

        $this->assertSame('telegram', Lead::latest('id')->first()->magnet_channel);
    }

    /** @test */
    public function default_channel_can_be_vk(): void
    {
        $this->landing->update(['lead_magnet_default_channel' => 'vk']);

        $this->postLead(['social' => 'something unparseable']);

        $this->assertSame('vk', Lead::latest('id')->first()->magnet_channel);
    }

    /** @test */
    public function flash_carries_telegram_deep_link_in_redirect_mode(): void
    {
        $resp = $this->postLead(['social' => '@my_telegram']);

        $lead = Lead::latest('id')->first();
        $expected = "https://t.me/test_magnet_bot?start={$lead->magnet_token}";

        $resp->assertRedirect()
            ->assertSessionHas('redirect_url', $expected)
            ->assertSessionHas('magnet_title', 'Тестовый PDF')
            ->assertSessionHas('magnet_channel', 'telegram');
    }

    /** @test */
    public function flash_carries_magnet_deep_link_in_page_mode(): void
    {
        MarketingSetting::first()->update(['magnet_delivery_mode' => 'page']);

        $resp = $this->postLead(['social' => 'vk.com/durov']);

        $lead = Lead::latest('id')->first();
        $expected = "https://vk.me/test_group?ref={$lead->magnet_token}";

        $resp->assertRedirect()
            ->assertSessionHas('magnet_deep_link', $expected)
            ->assertSessionMissing('redirect_url'); // в режиме page redirect_url не ставится
    }

    /** @test */
    public function landing_without_magnet_does_not_attach_token(): void
    {
        $plain = LandingPage::create([
            'title' => 'Plain landing',
            'slug' => 'plain-'.uniqid(),
            'lead_magnet_enabled' => false,
        ]);

        $this->postLead(['landing_page_id' => $plain->id, 'social' => '@durov']);

        $lead = Lead::latest('id')->first();
        $this->assertNull($lead->magnet_token);
        $this->assertNull($lead->magnet_channel);
    }

    /** @test */
    public function landing_redirect_url_overridden_by_magnet_deep_link(): void
    {
        // Если у лендинга стоит редирект И включён магнит — магнит важнее,
        // иначе юзер уйдёт мимо файла.
        $this->landing->update(['redirect_after_submit_url' => 'https://example.com/thanks']);

        $resp = $this->postLead(['social' => '@durov']);

        $lead = Lead::latest('id')->first();
        $resp->assertSessionHas('redirect_url', "https://t.me/test_magnet_bot?start={$lead->magnet_token}");
        // НЕ example.com
    }

    /** @test */
    public function magnet_token_is_unique_across_leads(): void
    {
        $this->postLead(['email' => 'one@example.com']);
        $this->postLead(['email' => 'two@example.com', 'landing_page_id' => $this->landing->id]);
        // второй на другой лендинг чтобы не сработала проверка дубликата
        $other = LandingPage::create([
            'title' => 'Other', 'slug' => 'other-'.uniqid(),
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'magnets/x.pdf',
            'lead_magnet_default_channel' => 'telegram',
        ]);
        $this->postLead(['email' => 'three@example.com', 'landing_page_id' => $other->id]);

        $tokens = Lead::whereNotNull('magnet_token')->pluck('magnet_token')->all();
        $this->assertSame(count($tokens), count(array_unique($tokens)));
    }

    /** @test */
    public function delivery_channel_manager_resolves_all_three_channels(): void
    {
        $mgr = app(DeliveryChannelManager::class);

        $this->assertTrue($mgr->has('telegram'));
        $this->assertTrue($mgr->has('vk'));
        $this->assertTrue($mgr->has('max'));
        $this->assertFalse($mgr->has('whatsapp'));

        $this->assertSame('telegram', $mgr->get('telegram')->name());
        $this->assertSame('vk', $mgr->get('vk')->name());
        $this->assertSame('max', $mgr->get('max')->name());
    }
}
