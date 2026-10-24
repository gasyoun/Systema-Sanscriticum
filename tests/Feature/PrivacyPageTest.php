<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6373 · public /privacy page (152-ФЗ + Google OAuth disclosure).
 *
 * Пререквизит подачи Google OAuth consent screen на верификацию sensitive
 * scope calendar (docs/GOOGLE_CALENDAR_VERIFICATION_PACK_H4434.md шаг 2):
 * https://samskrte.ru/privacy должен отвечать 200 БЕЗ auth и раскрывать
 * обработку персональных данных, cookies и OAuth-данные Google (scope
 * email/profile живут, calendar готовится).
 */
class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // H6373: страницы контента не зависят от Vite-ассетов; в CI/worktree
        // manifest может отсутствовать — рендерим без него.
        $this->withoutVite();
    }

    public function test_privacy_page_returns_200_without_auth(): void
    {
        $this->get('/privacy')->assertOk();
    }

    public function test_privacy_page_discloses_brand_site_contact_and_google_oauth(): void
    {
        $response = $this->get('/privacy');

        $response->assertSee('Общество ревнителей санскрита', false);
        $response->assertSee('samskrte.ru', false);
        $response->assertSee('zabota@samskrte.ru', false);
        $response->assertSee('152-ФЗ', false);
        $response->assertSee('Cookies', false);
        $response->assertSee('Google', false);
        $response->assertSee('https://www.googleapis.com/auth/calendar', false);
        $response->assertSee('<code>email</code>', false);
    }

    public function test_footer_links_to_the_privacy_page(): void
    {
        $this->get('/privacy')->assertSee(route('privacy'), false);
    }
}
