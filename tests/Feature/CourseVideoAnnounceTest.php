<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4281: видео-анонс курса — hero продающей страницы показывает iframe с
 * распознанным видео вместо статичной обложки; нераспознанная ссылка не
 * подставляется как embed.
 */
class CourseVideoAnnounceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recognised_youtube_url_resolves_to_an_embed_url(): void
    {
        $course = Course::factory()->create([
            'video_announce_url' => 'https://www.youtube.com/watch?v=abc12345678',
        ]);

        $this->assertSame(
            'https://www.youtube.com/embed/abc12345678?autoplay=1',
            $course->videoAnnounceEmbedUrl(),
        );
    }

    public function test_unrecognised_url_resolves_to_null(): void
    {
        $course = Course::factory()->create([
            'video_announce_url' => 'https://example.com/not-a-video',
        ]);

        $this->assertNull($course->videoAnnounceEmbedUrl());
    }

    public function test_empty_url_resolves_to_null(): void
    {
        $course = Course::factory()->create(['video_announce_url' => null]);

        $this->assertNull($course->videoAnnounceEmbedUrl());
    }

    public function test_landing_page_renders_the_video_iframe_when_set(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'video_announce_url' => 'https://rutube.ru/video/deadbeef0000/',
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        $response->assertSee('https://rutube.ru/play/embed/deadbeef0000', false);
    }

    public function test_landing_page_falls_back_to_the_cover_image_without_a_video(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'video_announce_url' => null,
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        $response->assertDontSee('youtube.com/embed', false);
        $response->assertDontSee('rutube.ru/play/embed', false);
        $response->assertDontSee('vk.com/video_ext.php', false);
    }
}
