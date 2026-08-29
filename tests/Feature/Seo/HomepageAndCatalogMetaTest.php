<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageAndCatalogMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_copy_names_sanskrit_from_zero_and_keeps_org_jsonld(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'Образовательная платформа для глубокого изучения',
            $html
        );
        $this->assertStringContainsString('курсы санскрита с нуля', mb_strtolower($html));
        $this->assertStringContainsString('#org', $html);
        $this->assertStringContainsString('@samskrtamru', $html);
        $this->assertStringContainsString('og:image:width', $html);
        $this->assertStringContainsString('content="1200"', $html);
        $this->assertStringContainsString('og-main-preview.jpg', $html);
        $this->assertStringNotContainsString('og-membership-club.webp', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]*>.*курсы санскрита с нуля/su', mb_strtolower($html));
    }

    public function test_online_title_names_catalog_and_differs_from_homepage(): void
    {
        $home = $this->get('/')->assertOk()->getContent();
        $online = $this->get('/online')->assertOk()->getContent();

        preg_match('/<title>([^<]+)<\/title>/', $home, $homeTitle);
        preg_match('/<title>([^<]+)<\/title>/', $online, $onlineTitle);

        $this->assertNotSame('', $homeTitle[1] ?? '');
        $this->assertNotSame('', $onlineTitle[1] ?? '');
        $this->assertNotSame($homeTitle[1], $onlineTitle[1]);
        $this->assertStringContainsString('курсы санскрита онлайн', mb_strtolower($onlineTitle[1]));
        $this->assertStringContainsString('name="description"', $online);
    }
}
