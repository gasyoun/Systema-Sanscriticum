<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Services\Media\ResponsiveDerivatives;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Производные варианты ширины для srcset (19-10-2026).
 *
 * Инварианты, которые нельзя терять: исходник не трогается, апскейла нет,
 * повторный прогон идемпотентен, а отсутствие вариантов — не ошибка рендера.
 */
class ResponsiveDerivativesTest extends TestCase
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
        config()->set('media.responsive.quality', 80);
        config()->set('media.responsive.widths', [400, 800, 1200]);
        ResponsiveDerivatives::flushMemo();
    }

    public function test_generates_configured_widths_plus_native(): void
    {
        $this->photoPng('media/sample.png', 1200, 900);

        $result = app(ResponsiveDerivatives::class)->generate('media/sample.png');

        $widths = array_column($result['created'], 'width');
        $this->assertSame([400, 800, 1200], $widths, '1200w — нативная ширина, апскейла нет');

        $fs = Storage::disk('public');
        $this->assertTrue($fs->exists('media/sample-w400.webp'));
        $this->assertTrue($fs->exists('media/sample-w1200.webp'));
        $this->assertFalse($fs->exists('media/sample-w1600.webp'));

        $info = getimagesizefromstring((string) $fs->get('media/sample-w800.webp'));
        $this->assertSame(800, $info[0]);
        $this->assertLessThan($fs->size('media/sample.png'), $fs->size('media/sample-w800.webp'));
    }

    public function test_card_sized_source_gets_its_own_width(): void
    {
        // Боевой случай: 532×399 PNG показывается в карточке 533 px. Без
        // нативного варианта браузер взял бы 400w и карточка стала бы мыльной.
        $this->photoPng('media/card.png', 532, 399);

        $result = app(ResponsiveDerivatives::class)->generate('media/card.png');

        $this->assertSame([400, 532], array_column($result['created'], 'width'));
        $this->assertTrue(Storage::disk('public')->exists('media/card-w532.webp'));
        $this->assertTrue(Storage::disk('public')->exists('media/card-w400.webp'));

        ResponsiveDerivatives::flushMemo();
        $set = app(ResponsiveDerivatives::class)->variantsForUrl('/storage/media/card.png');

        $this->assertNotNull($set);
        $this->assertStringContainsString('card-w400.webp 400w', $set['srcset']);
        $this->assertStringContainsString('card-w532.webp 532w', $set['srcset'], 'нативная ширина обязана попасть в srcset');

        $filtered = app(ResponsiveDerivatives::class)->variantsForUrl('/storage/media/card.png', [400]);
        $this->assertStringContainsString('card-w400.webp 400w', $filtered['srcset']);
        $this->assertStringNotContainsString('card-w532.webp', $filtered['srcset']);
    }

    public function test_source_file_is_never_modified(): void
    {
        $this->photoPng('media/sample.png', 1200, 900);
        $before = Storage::disk('public')->get('media/sample.png');

        app(ResponsiveDerivatives::class)->generate('media/sample.png');

        $this->assertSame($before, Storage::disk('public')->get('media/sample.png'));
    }

    public function test_second_run_is_idempotent(): void
    {
        $this->photoPng('media/sample.png', 1200, 900);

        $derivatives = app(ResponsiveDerivatives::class);
        $derivatives->generate('media/sample.png');
        $second = $derivatives->generate('media/sample.png');

        $this->assertSame([], $second['created']);
        $this->assertSame(['fresh', 'fresh', 'fresh'], $second['skipped']);
    }

    public function test_small_source_gets_only_its_native_width(): void
    {
        $this->photoPng('media/small.png', 300, 200);

        $result = app(ResponsiveDerivatives::class)->generate('media/small.png');

        $this->assertSame([300], array_column($result['created'], 'width'));
        $this->assertTrue(Storage::disk('public')->exists('media/small-w300.webp'));
        $this->assertFalse(Storage::disk('public')->exists('media/small-w400.webp'));
    }

    public function test_srcset_is_built_from_existing_variants(): void
    {
        $this->photoPng('media/sample.png', 1200, 900);
        $derivatives = app(ResponsiveDerivatives::class);
        $derivatives->generate('media/sample.png');
        ResponsiveDerivatives::flushMemo();

        $relative = $derivatives->variantsForUrl('/storage/media/sample.png');
        $this->assertNotNull($relative);
        $this->assertStringContainsString('sample-w400.webp 400w', $relative['srcset']);
        $this->assertStringContainsString('sample-w800.webp 800w', $relative['srcset']);

        ResponsiveDerivatives::flushMemo();
        $absolute = $derivatives->variantsForUrl('https://samskrte.ru/storage/media/sample.png?x=1');
        $this->assertNotNull($absolute);
        $this->assertSame($relative['srcset'], $absolute['srcset']);
    }

    public function test_missing_variants_return_null_and_keep_original(): void
    {
        $this->photoPng('media/plain.png', 1200, 900);

        $this->assertNull(app(ResponsiveDerivatives::class)->variantsForUrl('/storage/media/plain.png'));
        $this->assertNull(app(ResponsiveDerivatives::class)->variantsForUrl('/images/logo.png'));
        $this->assertNull(app(ResponsiveDerivatives::class)->variantsForUrl(''));
    }

    private function photoPng(string $path, int $width, int $height): void
    {
        mt_srand(20261019);

        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                // Зерно обязательно: без него PNG сжимает градиент почти
                // идеально, и WebP-вариант окажется БОЛЬШЕ исходника.
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
