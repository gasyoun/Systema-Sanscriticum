<?php

declare(strict_types=1);

namespace Tests\Feature\TrackedLinks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rl — ключи автопостинга рилсов: ролик × площадка (yt, rt, vkv) ведёт на
 * карточку своего курса с раздельной атрибуцией площадки.
 */
class ReelsAutopostLinksTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNELS = [
        'yt' => ['youtube_shorts', 'shorts_description'],
        'rt' => ['rutube', 'video_description'],
        'vkv' => ['vk_video', 'video_description'],
    ];

    public function test_every_reel_has_a_key_on_each_platform(): void
    {
        $keys = array_filter(array_keys(config('tracked_links.links')), fn (string $k): bool => str_starts_with($k, 'rl-'));

        $this->assertCount(112 * 3, $keys);
        foreach (array_keys(self::CHANNELS) as $channel) {
            $this->assertCount(112, array_filter($keys, fn (string $k): bool => str_starts_with($k, "rl-{$channel}-")));
        }
    }

    public function test_keys_route_to_the_course_card_with_platform_attribution(): void
    {
        $samples = [
            'ac001' => ['/k/astronomiia-dlia-astrologov-2-potok-osen-2025', 'astronomy'],
            'yoy01' => ['/k/yoga-vasistha', 'yogavasishtha'],
        ];

        foreach ($samples as $id => [$destination, $term]) {
            foreach (self::CHANNELS as $channel => [$source, $medium]) {
                $token = "rl-{$channel}-{$id}";
                $this->flushSession();
                $this->get('/ga/'.$token)->assertRedirect($destination);

                $attribution = session(config('tracked_links.session_key'));
                $this->assertSame($source, $attribution['utm_source'], "{$token} source");
                $this->assertSame($medium, $attribution['utm_medium'], "{$token} medium");
                $this->assertSame('reels_autopost_2026', $attribution['utm_campaign'], "{$token} campaign");
                $this->assertSame("rl_{$id}", $attribution['utm_content'], "{$token} content");
                $this->assertSame($term, $attribution['utm_term'], "{$token} term");
            }
        }
    }

    public function test_duplicate_fragments_and_unknown_channels_have_no_key(): void
    {
        $this->get('/ga/rl-yt-ac024')->assertNotFound();
        $this->get('/ga/rl-tg-ac001')->assertNotFound();
    }
}
