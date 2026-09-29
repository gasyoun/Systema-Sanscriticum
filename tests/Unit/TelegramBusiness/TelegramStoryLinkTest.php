<?php

declare(strict_types=1);

namespace Tests\Unit\TelegramBusiness;

use App\Services\TelegramBusiness\TelegramStoryLink;
use Tests\TestCase;

final class TelegramStoryLinkTest extends TestCase
{
    public function test_curated_campaign_link_wins_over_source_post(): void
    {
        self::assertSame('https://samskrte.ru/ga/m26-rs-st-lesson-20260927-01', TelegramStoryLink::fromPost([
            'caption' => 'Lesson https://samskrte.ru/ga/m26-rs-st-lesson-20260927-01',
            'chat' => ['username' => 'samskrte'], 'message_id' => 620,
        ]));
    }

    public function test_source_post_is_the_fallback(): void
    {
        self::assertSame('https://t.me/samskrtamru/4169', TelegramStoryLink::fromPost([
            'chat' => ['username' => 'samskrtamru'], 'message_id' => 4169,
        ]));
    }

    public function test_unconfigured_campaign_link_falls_back_to_source_post(): void
    {
        self::assertSame('https://t.me/samskrte/621', TelegramStoryLink::fromPost([
            'caption' => 'https://samskrte.ru/ga/unknown-campaign',
            'chat' => ['username' => 'samskrte'], 'message_id' => 621,
        ]));
    }

    public function test_link_area_points_to_the_same_url(): void
    {
        $area = json_decode(TelegramStoryLink::area('https://t.me/samskrte/620'), true);

        self::assertSame('https://t.me/samskrte/620', $area[0]['type']['url']);
        self::assertSame(50, $area[0]['position']['x_percentage']);
    }
}
