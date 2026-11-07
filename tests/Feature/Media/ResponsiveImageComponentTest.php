<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Services\Media\ResponsiveDerivatives;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * <x-responsive-image> — единственное место, где шаблон решает, отдавать ли
 * srcset (19-10-2026). Без вариантов компонент обязан вести себя как обычный
 * <img>: страница не должна ломаться до первого прогона команды.
 */
class ResponsiveImageComponentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD без поддержки WebP.');
        }

        Storage::fake('public');
        config()->set('media.responsive.enabled', true);
        config()->set('media.responsive.disk', 'public');
        config()->set('media.responsive.widths', [400, 800, 1200]);
        ResponsiveDerivatives::flushMemo();
    }

    public function test_emits_srcset_and_keeps_caller_attributes(): void
    {
        $this->photoPng('media/card.png', 1200, 900);
        app(ResponsiveDerivatives::class)->generate('media/card.png');
        ResponsiveDerivatives::flushMemo();

        $html = Blade::render(
            '<x-responsive-image src="/storage/media/card.png" alt="Курс" width="533" height="400" sizes="533px" loading="lazy" class="cover" />'
        );

        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('card-w400.webp 400w', $html);
        $this->assertStringContainsString('card-w800.webp 800w', $html);
        $this->assertStringContainsString('sizes="533px"', $html);
        $this->assertStringContainsString('width="533"', $html);
        $this->assertStringContainsString('height="400"', $html);
        $this->assertStringContainsString('class="cover"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('alt="Курс"', $html);
    }

    public function test_without_variants_it_is_a_plain_img(): void
    {
        $this->photoPng('media/card.png', 1200, 900);

        $html = Blade::render('<x-responsive-image src="/storage/media/card.png" alt="Курс" width="533" />');

        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringContainsString('src="/storage/media/card.png"', $html);
        $this->assertStringContainsString('width="533"', $html);
    }

    public function test_absolute_url_resolves_like_storage_path(): void
    {
        $this->photoPng('media/card.png', 1200, 900);
        app(ResponsiveDerivatives::class)->generate('media/card.png');
        ResponsiveDerivatives::flushMemo();

        $html = Blade::render('<x-responsive-image src="https://samskrte.ru/storage/media/card.png" alt="" />');

        $this->assertStringContainsString('card-w800.webp 800w', $html);
    }

    public function test_empty_src_renders_nothing(): void
    {
        $html = Blade::render('<x-responsive-image :src="null" alt="x" />');

        $this->assertSame('', trim($html));
    }

    private function photoPng(string $path, int $width, int $height): void
    {
        mt_srand(20261019);

        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                $colour = imagecolorallocate(
                    $image,
                    max(0, min(255, (int) (127 + 100 * sin($x / 60.0)) + mt_rand(-25, 25))),
                    max(0, min(255, (int) (127 + 100 * cos($y / 45.0)) + mt_rand(-25, 25))),
                    max(0, min(255, 160 + mt_rand(-25, 25))),
                );
                imagefilledrectangle($image, $x, $y, min($x + 1, $width - 1), min($y + 1, $height - 1), $colour);
            }
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $bytes);
    }
}
