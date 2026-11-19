<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Services\Media\ResponsiveDerivatives;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `media:responsive-variants` (19-10-2026) — backfill и ежедневный добор.
 *
 * Проверяем ровно то, от чего зависит безопасный запуск на проде: dry-run
 * ничего не пишет, повторный прогон не пересобирает свежее, лимит соблюдается.
 */
class ResponsiveVariantsCommandTest extends TestCase
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
        config()->set('media.responsive.widths', [400, 800]);
        config()->set('media.responsive.dirs', ['media']);
        config()->set('media.responsive.min_bytes', 0);
        ResponsiveDerivatives::flushMemo();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->photoPng('media/one.png');

        $this->artisan('media:responsive-variants', ['--dry-run' => true])->assertSuccessful();

        $this->assertFalse(Storage::disk('public')->exists('media/one-w400.webp'));
    }

    public function test_backfill_creates_variants_and_is_idempotent(): void
    {
        $this->photoPng('media/one.png');

        $this->artisan('media:responsive-variants')->assertSuccessful();

        $fs = Storage::disk('public');
        $this->assertTrue($fs->exists('media/one-w400.webp'));
        $this->assertTrue($fs->exists('media/one-w800.webp'));

        $before = count($fs->allFiles('media'));

        $exitCode = Artisan::call('media:responsive-variants');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('вариантов 0', $output, $output);
        $this->assertSame($before, count($fs->allFiles('media')), 'повторный прогон не должен плодить файлы');
        $this->assertFalse($fs->exists('media/one-w400-w400.webp'), 'вариант не должен становиться исходником');
    }

    public function test_limit_caps_processed_sources(): void
    {
        $this->photoPng('media/one.png');
        $this->photoPng('media/two.png');

        $this->artisan('media:responsive-variants', ['--limit' => 1])->assertSuccessful();

        $fs = Storage::disk('public');
        $first = $fs->exists('media/one-w400.webp');
        $second = $fs->exists('media/two-w400.webp');

        $this->assertTrue($first xor $second, 'ровно один исходник должен быть обработан при --limit=1');
    }

    private function photoPng(string $path, int $width = 1200, int $height = 900): void
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
