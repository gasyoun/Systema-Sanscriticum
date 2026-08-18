<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\Course;
use App\Models\User;
use App\Services\Design\CoverImportOutcome;
use App\Services\Design\CoverToBannerImporter;
use App\Services\Media\WebpTranscoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Автоперевод обложек витрины в WebP (H3082).
 *
 * Замер 18-08-2026: 89 обложек на 27,6 МБ, потому что фотографии лежали в PNG.
 * Два рубежа обязаны работать без человека — наблюдатель на загрузке и
 * ежедневная уборка, — и оба обязаны молчать там, где конвертация навредит.
 */
class CoversToWebpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(WebpTranscoder::class)->supported()) {
            $this->markTestSkipped('GD без поддержки WebP.');
        }

        Storage::fake('public');
        config()->set('media.webp.enabled', true);
        config()->set('media.webp.disk', 'public');
        config()->set('media.webp.delete_original', true);
    }

    /**
     * Картинка, ведущая себя как фотография: плавный тон плюс мелкое зерно.
     *
     * Зерно здесь не для красоты. Без него PNG сжимает плавный градиент почти
     * идеально, и никакого перевеса, ради которого всё затевалось, в фикстуре
     * не будет. С ним 533×399 даёт PNG ~435 КБ против WebP ~40 КБ — та же
     * десятикратная разница, что замерена на боевых обложках 18-08-2026.
     *
     * mt_srand — чтобы размер фикстуры не плавал от прогона к прогону.
     */
    private function photoPng(string $path, int $w = 533, int $h = 399): int
    {
        mt_srand(20260818);

        $im = imagecreatetruecolor($w, $h);

        for ($x = 0; $x < $w; $x += 1) {
            for ($y = 0; $y < $h; $y += 1) {
                $base = (int) (127 + 100 * sin($x / 60.0) * cos($y / 45.0));
                imagesetpixel($im, $x, $y, imagecolorallocate(
                    $im,
                    max(0, min(255, $base + mt_rand(-14, 14))),
                    max(0, min(255, (int) ($base * 0.75) + mt_rand(-14, 14))),
                    max(0, min(255, 255 - $base + mt_rand(-14, 14))),
                ));
            }
        }

        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        Storage::disk('public')->put($path, $bytes);

        return strlen($bytes);
    }

    /** @test */
    public function the_sweep_converts_a_png_cover_and_repoints_the_course(): void
    {
        $before = $this->photoPng('courses/cover-one.png');

        $course = Course::factory()->create(['image_path' => 'courses/cover-one.png']);

        $this->artisan('media:covers-to-webp')->assertSuccessful();

        $course->refresh();

        $this->assertSame('courses/cover-one.webp', $course->image_path,
            'путь курса обязан указывать на webp-версию');
        Storage::disk('public')->assertExists('courses/cover-one.webp');
        Storage::disk('public')->assertMissing('courses/cover-one.png');

        $after = strlen((string) Storage::disk('public')->get('courses/cover-one.webp'));
        $this->assertLessThan($before, $after, 'webp обязан быть легче исходного PNG');
    }

    /** @test */
    public function the_sweep_is_idempotent(): void
    {
        $this->photoPng('courses/cover-two.png');
        $course = Course::factory()->create(['image_path' => 'courses/cover-two.png']);

        $this->artisan('media:covers-to-webp')->assertSuccessful();
        $first = $course->fresh()->image_path;

        // Второй прогон не должен ни найти работу, ни тронуть путь: иначе
        // ежедневное расписание переписывало бы обложки вечно.
        $this->artisan('media:covers-to-webp')->assertSuccessful();

        $this->assertSame($first, $course->fresh()->image_path);
        Storage::disk('public')->assertExists('courses/cover-two.webp');
    }

    /** @test */
    public function uploading_a_cover_converts_it_in_the_same_request(): void
    {
        $this->photoPng('courses/fresh.png');

        $course = Course::factory()->create(['image_path' => 'courses/fresh.png']);

        // Наблюдатель отработал на создании — уборка тут ни при чём.
        $this->assertSame('courses/fresh.webp', $course->fresh()->image_path);
        Storage::disk('public')->assertMissing('courses/fresh.png');
    }

    /** @test */
    public function a_cover_that_would_grow_keeps_its_original_format(): void
    {
        // Сплошная заливка — классический случай, когда PNG легче WebP.
        $im = imagecreatetruecolor(800, 600);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagepng($im);
        $flat = (string) ob_get_clean();
        imagedestroy($im);

        Storage::disk('public')->put('courses/flat.png', $flat);
        config()->set('media.webp.min_gain', 0.90); // требуем заведомо недостижимый выигрыш

        $course = Course::factory()->create(['image_path' => 'courses/flat.png']);

        $this->assertSame('courses/flat.png', $course->fresh()->image_path,
            'без выигрыша по весу формат менять нельзя');
        Storage::disk('public')->assertExists('courses/flat.png');
    }

    /** @test */
    public function a_curator_media_id_is_left_alone(): void
    {
        // В этой же колонке у части записей лежит числовой id медиа Curator,
        // а не путь к файлу (layouts/promo.blade.php). Трогать его нельзя.
        $course = Course::factory()->create(['image_path' => '4217']);

        $this->artisan('media:covers-to-webp')->assertSuccessful();

        $this->assertSame('4217', $course->fresh()->image_path);
    }

    /** @test */
    public function a_missing_file_does_not_break_the_sweep(): void
    {
        Course::factory()->create(['image_path' => 'courses/gone.png']);
        $this->photoPng('courses/present.png');
        $ok = Course::factory()->create(['image_path' => 'courses/present.png']);

        $this->artisan('media:covers-to-webp')->assertSuccessful();

        // Битая строка не должна останавливать прогон по остальным курсам.
        $this->assertSame('courses/present.webp', $ok->fresh()->image_path);
    }

    /** @test */
    public function dry_run_changes_nothing(): void
    {
        $this->photoPng('courses/dry.png');
        config()->set('media.webp.enabled', false);
        $course = Course::factory()->create(['image_path' => 'courses/dry.png']);
        config()->set('media.webp.enabled', true);

        $this->artisan('media:covers-to-webp', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('courses/dry.png', $course->fresh()->image_path);
        Storage::disk('public')->assertExists('courses/dry.png');
    }

    /**
     * Композиция с соседним механизмом: обложка, ставшая WebP, обязана
     * по-прежнему переноситься в пустой слот баннера.
     *
     * Проверка не декоративная. CoverToBannerImporter отбирает файлы по своей
     * карте кодеков, и не окажись там IMAGETYPE_WEBP — автоперевод обложек
     * тихо оставил бы матрицу «Дизайн курсов» без единого импорта.
     *
     * @test
     */
    public function a_converted_cover_still_imports_into_a_banner_slot(): void
    {
        $this->photoPng('courses/importable.png', 1600, 1200);

        $course = Course::factory()->create(['image_path' => 'courses/importable.png']);
        $this->assertSame('courses/importable.webp', $course->fresh()->image_path);

        $result = app(CoverToBannerImporter::class)->import($course->fresh(), User::factory()->create());

        $this->assertSame(CoverImportOutcome::Imported, $result->outcome,
            'WebP-обложка обязана оставаться пригодной для переноса в баннер');
    }

    /** @test */
    public function the_kill_switch_stops_both_layers(): void
    {
        config()->set('media.webp.enabled', false);

        $this->photoPng('courses/off.png');
        $course = Course::factory()->create(['image_path' => 'courses/off.png']);

        $this->artisan('media:covers-to-webp')->assertSuccessful();

        $this->assertSame('courses/off.png', $course->fresh()->image_path);
        Storage::disk('public')->assertExists('courses/off.png');
    }
}
