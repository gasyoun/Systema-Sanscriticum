<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\VideoEmbed;
use PHPUnit\Framework\TestCase;

class VideoEmbedTest extends TestCase
{
    /** @test */
    public function parses_youtube_forms_including_shorts(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=abc12345678',
            'https://youtu.be/abc12345678',
            'https://www.youtube.com/shorts/abc12345678',
        ] as $url) {
            $this->assertSame('https://www.youtube.com/embed/abc12345678?autoplay=1', VideoEmbed::embed($url), $url);
            $this->assertSame('https://img.youtube.com/vi/abc12345678/hqdefault.jpg', VideoEmbed::poster($url), $url);
        }
    }

    /** @test */
    public function parses_rutube_video_and_shorts(): void
    {
        $this->assertSame('https://rutube.ru/play/embed/deadbeef0000', VideoEmbed::embed('https://rutube.ru/video/deadbeef0000/'));
        $this->assertSame('https://rutube.ru/play/embed/8655e8b38e8677d177f6d377357d71b8', VideoEmbed::embed('https://rutube.ru/shorts/8655e8b38e8677d177f6d377357d71b8/'));
    }

    /** @test */
    public function parses_vk_video_and_clip(): void
    {
        $this->assertStringContainsString('oid=-12345&id=67890', VideoEmbed::embed('https://vk.com/video-12345_67890'));
        $this->assertStringContainsString('oid=-88831040&id=456240444', VideoEmbed::embed('https://vk.com/clip-88831040_456240444'));
    }

    /** @test */
    public function parses_kinescope_forms(): void
    {
        $id = 'aB12cd34ef';
        foreach ([
            "https://kinescope.io/{$id}",
            "https://kinescope.io/embed/{$id}",
            "https://kinescope.io/video/{$id}",
        ] as $url) {
            $this->assertSame("https://kinescope.io/embed/{$id}", VideoEmbed::embed($url), $url);
            $this->assertTrue(VideoEmbed::isKinescope($url), $url);
            $this->assertSame($id, VideoEmbed::kinescopeId($url), $url);
        }
    }

    /** @test */
    public function returns_null_for_unknown(): void
    {
        $this->assertNull(VideoEmbed::embed('https://example.com/foo'));
        $this->assertNull(VideoEmbed::poster('https://rutube.ru/shorts/8655e8b38e8677d177f6d377357d71b8/'));
        $this->assertFalse(VideoEmbed::isKinescope('https://youtube.com/watch?v=abc12345678'));
    }
}
