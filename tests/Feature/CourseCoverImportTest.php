<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\User;
use App\Services\Design\CourseDesignAssetService;
use App\Services\Design\CoverImportOutcome;
use App\Services\Design\CoverToBannerImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Перенос обложки витрины (courses.image_path) в пустой слот баннера 4:3.
 *
 * Два инварианта, ради которых этот набор и существует:
 *  • обложка витрины остаётся нетронутой — байт-в-байт и по значению поля;
 *  • занятый слот не перезаписывается никогда, даже если обложка «лучше».
 */
class CourseCoverImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        // Предмет этого набора — импортёр баннеров, а не автоперевод обложек в
        // WebP (H3082). Фикстуры здесь — нарочно собранные байты PNG/JPEG, и
        // наблюдатель, переписывающий обложку при сохранении курса, ломал бы
        // ровно те проверки, ради которых байты и собирались (побайтовая копия,
        // «jpeg остаётся jpeg», центр кадра). Композицию двух механизмов
        // проверяет CoversToWebpTest::a_converted_cover_still_imports_into_a_banner_slot.
        config()->set('media.webp.enabled', false);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/tmp/course-design-import').'/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function importer(): CoverToBannerImporter
    {
        return app(CoverToBannerImporter::class);
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    /** Положить курсу обложку с готовым содержимым и вернуть путь на диске. */
    private function cover(Course $course, string $contents, string $name = 'cover.png'): string
    {
        $path = 'courses/'.$name;
        Storage::disk('public')->put($path, $contents);
        $course->update(['image_path' => $path]);

        return $path;
    }

    /** Двухцветная картинка: левая половина красная, правая синяя. */
    private function halves(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($im, 200, 40, 40));
        imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($im, 40, 60, 200));

        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** Полностью прозрачный PNG. */
    private function transparent(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));

        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** Шум: сжимается плохо, поэтому годится для проверки лимита размера. */
    private function noise(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7 + $y * 13) % 256, ($x * 31 + $y * 3) % 256, ($x * 17 + $y * 29) % 256));
            }
        }

        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int, 2: int} r, g, b пикселя картинки в слоте. */
    private function pixel(CourseDesignAsset $asset, int $x, int $y): array
    {
        $im = imagecreatefromstring(Storage::disk($asset->disk)->get($asset->path));
        $rgb = imagecolorsforindex($im, imagecolorat($im, $x, $y));
        imagedestroy($im);

        return [$rgb['red'], $rgb['green'], $rgb['blue']];
    }

    /** @test */
    public function it_crops_the_cover_to_exact_four_to_three_and_fills_the_empty_slot(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, $this->halves(1600, 900));

        $result = $this->importer()->import($course, $this->actor());

        $this->assertTrue($result->isImported(), 'Ожидали успешный перенос, получили: '.$result->outcome->value.' '.$result->detail);

        $asset = $result->asset;
        $this->assertSame('4:3', $asset->format);
        $this->assertSame(1200, $asset->width);
        $this->assertSame(900, $asset->height);
        $this->assertTrue($asset->ratioMatches());
        Storage::disk('public')->assertExists($asset->path);
        $this->assertStringContainsString('course-design/'.$course->id.'/4x3-', $asset->path);
    }

    /** @test */
    public function the_crop_is_centred_not_top_left(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, $this->halves(1600, 900));

        $asset = $this->importer()->import($course, $this->actor())->asset;

        // Кроп по центру: граница цветов из середины исходника (x=800) уезжает
        // в середину результата (x=600). Кроп от левого верхнего угла оставил бы
        // её на 800 — тогда пиксель 700 был бы красным, а не синим.
        $this->assertSame([200, 40, 40], $this->pixel($asset, 500, 450), 'Слева от центра ожидали красный.');
        $this->assertSame([40, 60, 200], $this->pixel($asset, 700, 450), 'Справа от центра ожидали синий.');
    }

    /** @test */
    public function a_tall_cover_is_cropped_vertically(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, $this->halves(900, 1600));

        $asset = $this->importer()->import($course, $this->actor())->asset;

        $this->assertSame(900, $asset->width);
        $this->assertSame(675, $asset->height);
    }

    /** @test */
    public function the_original_shop_cover_is_untouched(): void
    {
        $course = Course::factory()->create();
        $path = $this->cover($course, $this->halves(1600, 900));
        $before = Storage::disk('public')->get($path);

        $this->importer()->import($course, $this->actor());

        // Витрина обязана выглядеть после переноса ровно так же, как до него.
        Storage::disk('public')->assertExists($path);
        $this->assertSame($before, Storage::disk('public')->get($path));
        $this->assertSame($path, $course->fresh()->image_path);
    }

    /** @test */
    public function an_occupied_four_to_three_slot_is_never_overwritten(): void
    {
        $course = Course::factory()->create();
        $actor = $this->actor();

        $taken = app(CourseDesignAssetService::class)->store(
            $course, '4:3', UploadedFile::fake()->image('hand-made.jpg', 1200, 900), null, null, $actor,
        );

        $this->cover($course, $this->halves(1600, 900));

        $result = $this->importer()->import($course->fresh(), $actor);

        $this->assertSame(CoverImportOutcome::SlotTaken, $result->outcome);
        $this->assertSame(1, CourseDesignAsset::where('course_id', $course->id)->count());

        $fresh = $taken->fresh();
        $this->assertSame($taken->path, $fresh->path);
        $this->assertSame($taken->size, $fresh->size);
        Storage::disk('public')->assertExists($taken->path);
    }

    /** @test */
    public function other_formats_are_left_alone(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, $this->halves(1600, 900));

        $this->importer()->import($course, $this->actor());

        // Переносим ровно в один слот: 16:9 и 9:16 остаются работой дизайнера.
        $this->assertSame(['4:3'], CourseDesignAsset::where('course_id', $course->id)->pluck('format')->all());
    }

    /** @test */
    public function a_cover_already_in_four_to_three_is_copied_without_re_encoding(): void
    {
        $course = Course::factory()->create();
        $bytes = $this->halves(1200, 900);
        $this->cover($course, $bytes);

        $asset = $this->importer()->import($course, $this->actor())->asset;

        $this->assertSame(1200, $asset->width);
        $this->assertSame(900, $asset->height);
        $this->assertSame($bytes, Storage::disk('public')->get($asset->path), 'Пропорция уже та — файл обязан лечь байт-в-байт.');
    }

    /** @test */
    public function a_near_four_to_three_cover_is_cropped_by_default_and_copied_when_tolerance_allows(): void
    {
        // 533×399 — реальная обложка с прода: 1,3358 против 1,3333.
        $bytes = $this->halves(533, 399);

        $strict = Course::factory()->create();
        $this->cover($strict, $bytes, 'strict.png');
        $cropped = $this->importer()->import($strict, $this->actor())->asset;
        $this->assertSame([532, 399], [$cropped->width, $cropped->height]);

        config(['design_assets.import_copy_tolerance' => 0.02]);

        $lax = Course::factory()->create();
        $this->cover($lax, $bytes, 'lax.png');
        $kept = $this->importer()->import($lax, $this->actor())->asset;
        $this->assertSame([533, 399], [$kept->width, $kept->height]);
    }

    /** @test */
    public function a_course_without_a_cover_is_skipped(): void
    {
        $course = Course::factory()->create(['image_path' => null]);

        $result = $this->importer()->import($course, $this->actor());

        $this->assertSame(CoverImportOutcome::NoCover, $result->outcome);
        $this->assertSame(0, CourseDesignAsset::count());
    }

    /** @test */
    public function a_missing_file_on_disk_is_skipped_not_fatal(): void
    {
        $course = Course::factory()->create(['image_path' => 'courses/gone.png']);

        $result = $this->importer()->import($course, $this->actor());

        $this->assertSame(CoverImportOutcome::FileMissing, $result->outcome);
        $this->assertSame(0, CourseDesignAsset::count());
    }

    /** @test */
    public function a_corrupt_file_is_skipped(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, 'это вовсе не картинка', 'broken.png');

        $result = $this->importer()->import($course, $this->actor());

        $this->assertSame(CoverImportOutcome::Unreadable, $result->outcome);
        $this->assertSame(0, CourseDesignAsset::count());
    }

    /** @test */
    public function an_svg_cover_is_skipped_with_a_clear_reason(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"></svg>', 'vector.svg');

        $result = $this->importer()->import($course, $this->actor());

        // Unsupported там, где getimagesize распознаёт SVG (PHP 8.5), Unreadable
        // там, где нет. Важно ровно одно: это пропуск, а не ошибка — и в слот
        // векторный файл не попадает даже при пропорции ровно 4:3.
        $this->assertTrue($result->outcome->isSkip(), 'SVG — это пропуск с внятной причиной, а не ошибка.');
        $this->assertSame(0, CourseDesignAsset::count());
    }

    /** @test */
    public function a_raster_format_without_a_codec_is_skipped(): void
    {
        if (! function_exists('imagebmp')) {
            $this->markTestSkipped('В этой сборке PHP нет imagebmp.');
        }

        $course = Course::factory()->create();

        $im = imagecreatetruecolor(500, 300);
        ob_start();
        imagebmp($im);
        imagedestroy($im);
        $this->cover($course, (string) ob_get_clean(), 'cover.bmp');

        $result = $this->importer()->import($course, $this->actor());

        $this->assertSame(CoverImportOutcome::Unsupported, $result->outcome);
        $this->assertSame(0, CourseDesignAsset::count());
    }

    /** @test */
    public function a_cover_smaller_than_the_ratio_is_skipped(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, $this->halves(3, 2), 'tiny.png');

        $result = $this->importer()->import($course, $this->actor());

        $this->assertSame(CoverImportOutcome::TooSmall, $result->outcome);
        $this->assertSame(0, CourseDesignAsset::count());
    }

    /** @test */
    public function a_result_over_the_size_limit_is_skipped(): void
    {
        config(['design_assets.max_image_kb' => 1]);

        $course = Course::factory()->create();
        $this->cover($course, $this->noise(500, 300), 'noise.png');

        $result = $this->importer()->import($course, $this->actor());

        $this->assertSame(CoverImportOutcome::TooHeavy, $result->outcome);
        $this->assertSame(0, CourseDesignAsset::count());
        // Ничего не записали и на диск: каталог слотов курса не появился.
        $this->assertSame([], Storage::disk('public')->files('course-design/'.$course->id));
    }

    /** @test */
    public function a_transparent_png_keeps_its_alpha(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, $this->transparent(1600, 900), 'glass.png');

        $asset = $this->importer()->import($course, $this->actor())->asset;

        $im = imagecreatefromstring(Storage::disk('public')->get($asset->path));
        $alpha = imagecolorsforindex($im, imagecolorat($im, 0, 0))['alpha'];
        imagedestroy($im);

        $this->assertSame(127, $alpha, 'Прозрачный угол не должен приехать чёрным квадратом.');
    }

    /** @test */
    public function a_jpeg_cover_stays_a_jpeg(): void
    {
        $course = Course::factory()->create();
        $this->cover($course, (string) UploadedFile::fake()->image('photo.jpg', 1600, 900)->get(), 'photo.jpg');

        $asset = $this->importer()->import($course, $this->actor())->asset;

        $this->assertStringEndsWith('.jpg', $asset->path);
        $this->assertSame('image/jpeg', $asset->mime);
        $this->assertSame('photo.jpg', $asset->original_name);
    }

    /** @test */
    public function an_inactive_course_is_imported_too(): void
    {
        // Архивному курсу баннер тоже может понадобиться, а отфильтровать
        // неактивные можно прямо в таблице страницы.
        $course = Course::factory()->create(['is_active' => false]);
        $this->cover($course, $this->halves(1600, 900));

        $this->assertTrue($this->importer()->import($course, $this->actor())->isImported());
    }

    /** @test */
    public function no_temporary_files_are_left_behind(): void
    {
        $dir = storage_path('app/tmp/course-design-import');

        $ok = Course::factory()->create();
        $this->cover($ok, $this->halves(1600, 900), 'ok.png');
        $this->importer()->import($ok, $this->actor());

        $broken = Course::factory()->create();
        $this->cover($broken, 'мусор', 'broken2.png');
        $this->importer()->import($broken, $this->actor());

        $this->assertSame([], glob($dir.'/*') ?: [], 'После переноса временных файлов остаться не должно.');
    }
}
