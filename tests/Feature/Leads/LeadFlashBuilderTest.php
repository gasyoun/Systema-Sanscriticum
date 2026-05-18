<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\MarketingSetting;
use App\Services\Leads\LeadFlashBuilder;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadFlashBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): LeadFlashBuilder
    {
        return new LeadFlashBuilder(app(DeliveryChannelManager::class));
    }

    /** @test */
    public function returns_just_success_without_landing_or_magnet(): void
    {
        $lead = Lead::create([
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
        ]);

        $flash = $this->builder()->build($lead, null, []);

        $this->assertArrayHasKey('success', $flash);
        $this->assertArrayNotHasKey('yandex_id', $flash);
        $this->assertArrayNotHasKey('vk_id', $flash);
        $this->assertArrayNotHasKey('magnet_deep_links', $flash);
        $this->assertArrayNotHasKey('redirect_url', $flash);
    }

    /** @test */
    public function landing_pixels_and_redirect_url_propagate(): void
    {
        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'yandex_metrika_id' => 99887766,
            'vk_pixel_id' => 12345,
            'redirect_after_submit_url' => 'https://example.com/thanks',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
        ]);

        $flash = $this->builder()->build($lead, $landing, []);

        $this->assertEquals(99887766, $flash['yandex_id']);
        $this->assertEquals(12345, $flash['vk_id']);
        $this->assertSame('lead', $flash['conversion_event']);
        $this->assertSame('https://example.com/thanks', $flash['redirect_url']);
    }

    /** @test */
    public function magnet_in_redirect_mode_sets_redirect_to_lead_channel_and_lists_all_buttons(): void
    {
        // Все три канала полностью настроены — должны вылезти все три кнопки.
        MarketingSetting::create([
            'magnet_delivery_mode' => 'redirect',
            'tg_bot_username' => 'magnetbot',
            'tg_bot_token' => 'tg-token',
            'vk_group_screen_name' => 'mygroup',
            'vk_access_token' => 'vk-token',
            'max_bot_username' => 'maxbot',
            'max_bot_token' => 'max-token',
        ]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'm/x.pdf',
            'lead_magnet_title' => 'PDF',
            'redirect_after_submit_url' => 'https://example.com/should-be-overridden',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'TOK123', 'magnet_channel' => 'telegram',
        ]);

        $flash = $this->builder()->build($lead, $landing, []);

        // redirect_url теперь указывает на канал лида (Telegram).
        $this->assertSame('https://t.me/magnetbot?start=TOK123', $flash['redirect_url']);

        // Все три кнопки доступны — юзер может выбрать другой канал, если редирект не сработал.
        $this->assertArrayHasKey('magnet_deep_links', $flash);
        $this->assertCount(3, $flash['magnet_deep_links']);
        $this->assertSame('https://t.me/magnetbot?start=TOK123', $flash['magnet_deep_links']['telegram']);
        $this->assertStringContainsString('TOK123', $flash['magnet_deep_links']['vk']);
        $this->assertStringContainsString('TOK123', $flash['magnet_deep_links']['max']);

        $this->assertSame('PDF', $flash['magnet_title']);
        $this->assertSame('telegram', $flash['magnet_channel']);
    }

    /** @test */
    public function magnet_in_page_mode_does_not_set_redirect_url(): void
    {
        MarketingSetting::create([
            'magnet_delivery_mode' => 'page',
            'tg_bot_username' => 'magnetbot',
            'tg_bot_token' => 'tg-token',
        ]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'm/x.pdf',
            'lead_magnet_title' => 'PDF',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'TOK456', 'magnet_channel' => 'telegram',
        ]);

        $flash = $this->builder()->build($lead, $landing, []);

        // В page-режиме redirect_url не выставляется — юзер сам кликает кнопку.
        $this->assertArrayNotHasKey('redirect_url', $flash);
        $this->assertSame('https://t.me/magnetbot?start=TOK456', $flash['magnet_deep_links']['telegram']);
    }

    /** @test */
    public function all_three_channels_appear_when_fully_configured(): void
    {
        MarketingSetting::create([
            'magnet_delivery_mode' => 'page',
            'tg_bot_username' => 'tgbot', 'tg_bot_token' => 't',
            'vk_group_screen_name' => 'vkgroup', 'vk_access_token' => 'v',
            'max_bot_username' => 'maxbot', 'max_bot_token' => 'm',
        ]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true, 'lead_magnet_file_path' => 'm/x.pdf',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'TOK', 'magnet_channel' => 'telegram',
        ]);

        $flash = $this->builder()->build($lead, $landing, []);

        $this->assertSame(['telegram', 'vk', 'max'], array_keys($flash['magnet_deep_links']));
    }

    /** @test */
    public function only_fully_configured_channels_appear(): void
    {
        // Telegram полный, VK только screen_name без token, Max — пусто.
        MarketingSetting::create([
            'magnet_delivery_mode' => 'page',
            'tg_bot_username' => 'tgbot', 'tg_bot_token' => 't',
            'vk_group_screen_name' => 'vkgroup', // token не задан
        ]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true, 'lead_magnet_file_path' => 'm/x.pdf',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'TOK', 'magnet_channel' => 'telegram',
        ]);

        $flash = $this->builder()->build($lead, $landing, []);

        $this->assertSame(['telegram'], array_keys($flash['magnet_deep_links']));
    }

    /** @test */
    public function no_buttons_when_no_channel_has_token(): void
    {
        // Только usernames без tokens — ни один канал не считается «настроенным».
        MarketingSetting::create([
            'magnet_delivery_mode' => 'page',
            'tg_bot_username' => 'tgbot',
            'vk_group_screen_name' => 'vkgroup',
            'max_bot_username' => 'maxbot',
        ]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true, 'lead_magnet_file_path' => 'm/x.pdf',
        ]);
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'magnet_token' => 'TOK', 'magnet_channel' => 'telegram',
        ]);

        $flash = $this->builder()->build($lead, $landing, []);

        $this->assertArrayNotHasKey('magnet_deep_links', $flash);
        $this->assertArrayNotHasKey('magnet_title', $flash);
    }

    /** @test */
    public function blog_lead_uses_marketing_setting_pixels(): void
    {
        MarketingSetting::create([
            'blog_yandex_metrika_id' => 55,
            'blog_vk_pixel_id' => 77,
        ]);

        $lead = Lead::create([
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
            'source_article_slug' => 'how-to',
        ]);

        $flash = $this->builder()->build($lead, null, ['source_article_id' => 42]);

        // assertEquals (не Same): SQLite в тестах отдаёт числа как string,
        // MySQL — как int. Контракт flash — «передать ID как есть», тип не важен.
        $this->assertEquals(55, $flash['yandex_id']);
        $this->assertEquals(77, $flash['vk_id']);
        $this->assertSame('lead_from_article', $flash['conversion_event']);
    }

    /** @test */
    public function magnet_block_skipped_when_lead_has_no_token(): void
    {
        MarketingSetting::create([
            'magnet_delivery_mode' => 'page',
            'tg_bot_username' => 'bot', 'tg_bot_token' => 't',
        ]);

        $landing = LandingPage::create([
            'title' => 'L', 'slug' => 'l-'.uniqid(),
            'lead_magnet_enabled' => true,
            'lead_magnet_file_path' => 'm/x.pdf',
        ]);
        // magnet_token / magnet_channel не выставлены — magnet-ветка должна пропуститься,
        // т.е. flash не должен содержать magnet_* ключей.
        $lead = Lead::create([
            'landing_page_id' => $landing->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'x@x.com',
        ]);

        $flash = $this->builder()->build($lead, $landing, []);

        $this->assertArrayNotHasKey('magnet_deep_links', $flash);
        $this->assertArrayNotHasKey('magnet_title', $flash);
        $this->assertArrayNotHasKey('magnet_channel', $flash);
    }
}
