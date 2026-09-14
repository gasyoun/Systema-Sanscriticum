<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

/**
 * H3650: membership/calendar heads name dedicated OG stills; home keeps the generic preview.
 */
final class MembershipOgImageTest extends MembershipTestCase
{
    public function test_club_landing_head_names_club_and_basic_og_stills(): void
    {
        $this->clubTariff(1);

        $html = $this->get('/klub')->assertOk()->getContent();

        $this->assertStringContainsString('images/og-membership-club.webp', $html);
        $this->assertStringContainsString('images/og-membership-basic.webp', $html);
        $this->assertStringContainsString('property="og:image:width"', $html);
        $this->assertStringContainsString('content="1200"', $html);
        $this->assertStringContainsString('content="630"', $html);
        $this->assertStringNotContainsString('og-main-preview.jpg', $html);
    }

    public function test_autumn_storefront_head_names_calendar_og_still(): void
    {
        config()->set('features.membership_public_feed', true);

        $html = $this->get('https://samskrte.ru/osen-2026')->assertOk()->getContent();

        $this->assertStringContainsString('images/og-membership-autumn.webp', $html);
        $this->assertStringContainsString('property="og:image:width"', $html);
        $this->assertStringContainsString('content="1200"', $html);
        $this->assertStringContainsString('content="630"', $html);
        $this->assertStringNotContainsString('og-main-preview.jpg', $html);
        $this->assertStringNotContainsString('og-membership-club.webp', $html);
    }

    public function test_homepage_keeps_generic_og_preview(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('og-main-preview.jpg', $html);
        $this->assertStringNotContainsString('og-membership-club.webp', $html);
        $this->assertStringNotContainsString('og-membership-autumn.webp', $html);
    }

    public function test_committed_og_stills_are_1200_wide_webp(): void
    {
        $files = [
            'og-membership-club.webp' => [1200, 630],
            'og-membership-basic.webp' => [1200, 630],
            'og-membership-autumn.webp' => [1200, 630],
            'og-membership-club-1x1.webp' => [1200, 1200],
            'og-membership-basic-1x1.webp' => [1200, 1200],
            'og-membership-autumn-1x1.webp' => [1200, 1200],
        ];

        foreach ($files as $name => [$width, $height]) {
            $path = public_path('images/'.$name);
            $this->assertFileExists($path, $name.' missing from public/images');
            $info = getimagesize($path);
            $this->assertNotFalse($info, $name.' is not a readable image');
            $this->assertSame('image/webp', $info['mime'] ?? null, $name.' mime');
            $this->assertSame($width, $info[0], $name.' width');
            $this->assertSame($height, $info[1], $name.' height');
        }
    }
}
