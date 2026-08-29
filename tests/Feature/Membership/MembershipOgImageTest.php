<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

/**
 * H3650: /klub and autumn storefronts carry dedicated OG stills;
 * the homepage keeps images/og-main-preview.jpg.
 */
final class MembershipOgImageTest extends MembershipTestCase
{
    public function test_klub_head_uses_club_og_still_not_home_preview(): void
    {
        $this->clubTariff(1);

        $html = $this->get('/klub')->assertOk()->getContent();

        $this->assertStringContainsString('og-membership-club-h3650.webp', $html);
        $this->assertStringContainsString('property="og:image:width"', $html);
        $this->assertStringContainsString('content="1200"', $html);
        $this->assertStringContainsString('content="630"', $html);
        $this->assertStringNotContainsString('og-main-preview.jpg', $html);
    }

    public function test_homepage_keeps_generic_preview(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('og-main-preview.jpg', $html);
        $this->assertStringNotContainsString('og-membership-club-h3650.webp', $html);
        $this->assertStringNotContainsString('og-membership-basic-h3650.webp', $html);
        $this->assertStringNotContainsString('og-autumn-calendar-h3650.webp', $html);
    }

    public function test_autumn_storefronts_use_calendar_og_still(): void
    {
        config()->set('features.membership_public_feed', true);

        foreach ([
            'https://samskrte.ru/osen-2026',
            'https://samskrtam.ru/courses/autumn-2026',
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('og-autumn-calendar-h3650.webp', $html, $url);
            $this->assertStringContainsString('property="og:image:width"', $html, $url);
            $this->assertStringContainsString('content="1200"', $html, $url);
            $this->assertStringContainsString('content="630"', $html, $url);
        }
    }

    public function test_committed_stills_match_og_and_square_sizes(): void
    {
        $wide = [
            'og-membership-club-h3650.webp',
            'og-membership-basic-h3650.webp',
            'og-autumn-calendar-h3650.webp',
        ];
        $square = [
            'og-membership-club-h3650-1x1.webp',
            'og-membership-basic-h3650-1x1.webp',
            'og-autumn-calendar-h3650-1x1.webp',
        ];

        foreach ($wide as $name) {
            $path = public_path('images/'.$name);
            $this->assertFileExists($path, $name);
            $info = getimagesize($path);
            $this->assertNotFalse($info, $name);
            $this->assertSame(1200, $info[0], $name);
            $this->assertSame(630, $info[1], $name);
        }

        foreach ($square as $name) {
            $path = public_path('images/'.$name);
            $this->assertFileExists($path, $name);
            $info = getimagesize($path);
            $this->assertNotFalse($info, $name);
            $this->assertSame(1200, $info[0], $name);
            $this->assertSame(1200, $info[1], $name);
        }
    }
}
