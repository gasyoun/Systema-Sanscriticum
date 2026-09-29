<?php

declare(strict_types=1);

namespace Tests\Feature\Banners;

use App\Models\Course;
use App\Models\LessonBannerTemplate;
use App\Models\Teacher;
use App\Services\Banners\LessonBannerRenderer;
use App\Services\Banners\LessonBannerService;
use App\Services\Banners\LessonBannerTemplateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Плашки: номер в кружке (цифры вырезаны), водяной номер под верхним слоем
 * («Мягкий свет»), дополнительные поля spec и их проверка.
 */
class LessonBannerLayersTest extends TestCase
{
    use RefreshDatabase;

    private const BG = [240, 210, 140];     // бежевая плашка

    private const BADGE = [130, 7, 20];      // бордовый кружок

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['lesson_banners.fonts_dir' => storage_path('framework/testing/no-fonts-here')]);
    }

    public function test_badge_draws_a_filled_circle_with_knocked_out_digits(): void
    {
        $template = $this->template(['number' => $this->badgeField()]);

        $image = $this->render($template, ['date' => '', 'number' => '14']);

        // Середина кольца между краем круга и цифрами — цвет кружка.
        [$r, $g, $b] = $this->rgbAt($image, 100 + 4, 100 + 40);
        $this->assertEqualsWithDelta(self::BADGE[0], $r, 25);

        // Внутри круга есть «дырки» цвета плашки — вырезанные цифры.
        $holes = 0;
        for ($x = 110; $x < 170; $x++) {
            for ($y = 120; $y < 160; $y++) {
                [$r, $g, $b] = $this->rgbAt($image, $x, $y);
                if (abs($r - self::BG[0]) < 30 && abs($g - self::BG[1]) < 30 && abs($b - self::BG[2]) < 30) {
                    $holes++;
                }
            }
        }
        $this->assertGreaterThan(40, $holes, 'цифры должны быть вырезаны до цвета плашки');

        // За кругом — плашка без изменений.
        [$r, $g, $b] = $this->rgbAt($image, 95, 95);
        $this->assertEqualsWithDelta(self::BG[0], $r, 12);
    }

    public function test_watermark_is_drawn_under_the_overlay_and_blends_with_soft_light(): void
    {
        // Верхний слой: непрозрачный синий квадрат в левой половине, остальное прозрачно.
        $overlay = imagecreatetruecolor(400, 300);
        imagesavealpha($overlay, true);
        imagealphablending($overlay, false);
        imagefill($overlay, 0, 0, (int) imagecolorallocatealpha($overlay, 0, 0, 0, 127));
        imagefilledrectangle($overlay, 0, 0, 199, 299, (int) imagecolorallocate($overlay, 20, 40, 200));
        Storage::disk('public')->put('tpl/overlay.png', $this->png($overlay));

        $template = $this->template([
            'watermark' => [
                'source' => 'number', 'layer' => 'under', 'blend' => 'soft_light', 'opacity' => 0.6,
                'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300, 'align' => 'center', 'size_px' => 380,
                'font' => 'Missing.ttf', 'color' => '#000000', 'fit' => false, 'format' => '{N}',
            ],
        ], overlay: 'tpl/overlay.png');

        $image = $this->render($template, ['date' => '', 'number' => '', 'watermark' => '8']);

        // Под непрозрачной частью верхнего слоя водяного знака не видно вовсе.
        $this->assertSame([20, 40, 200], $this->rgbAt($image, 100, 150, exact: true));

        // Справа (верхний слой прозрачен) «8» затемнила плашку мягким светом,
        // но не залила её чёрным: заливка 60 % и режим «Мягкий свет».
        // Смотрим синий канал: чёрный мягкий свет даёт b², и на светлом красном
        // (240 → ≈232) сдвиг тонет в шуме JPEG, а на синем (140 → ≈102) виден.
        $darkened = 0;
        $black = 0;
        for ($x = 200; $x < 400; $x += 2) {
            for ($y = 0; $y < 300; $y += 2) {
                [, , $b] = $this->rgbAt($image, $x, $y);
                if ($b < self::BG[2] - 20) {
                    $darkened++;
                }
                if ($b < 60) {
                    $black++;
                }
            }
        }
        $this->assertGreaterThan(50, $darkened);
        $this->assertSame(0, $black);
    }

    public function test_extra_number_field_gets_its_own_format_and_badge_is_empty_for_overview(): void
    {
        $template = $this->template([
            'number' => $this->badgeField(),
            'watermark' => ['source' => 'number', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300, 'size_px' => 200, 'format' => '№{N}'],
        ]);
        $service = app(LessonBannerService::class);
        $start = Carbon::parse('2026-09-24 19:00:00');

        $texts = $service->textsFor($template, $start, 7, false);
        ksort($texts);
        $this->assertSame(['date' => '24.09.2026', 'number' => '7', 'watermark' => '№7'], $texts);

        // Обзорное: кружок «Обзорным занятием» не заполнить — поле пустое и не рисуется.
        $this->assertSame('', $service->textsFor($template, $start, null, true)['number']);
    }

    public function test_under_fields_require_an_overlay_of_the_same_size(): void
    {
        $store = app(LessonBannerTemplateStore::class);
        $course = $this->course();
        $spec = $this->specJson(['watermark' => [
            'source' => 'number', 'layer' => 'under', 'blend' => 'soft_light', 'opacity' => 0.59,
            'x' => -20, 'y' => 0, 'w' => 500, 'h' => 320, 'size_px' => 300, 'format' => '{N}',
        ]]);

        try {
            $store->store($course->id, null, $this->upload($this->canvas(400, 300)), $spec);
            $this->fail('без overlay.png шаблон с under-полем приниматься не должен');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('верхний слой', $e->getMessage());
        }

        try {
            $store->store($course->id, null, $this->upload($this->canvas(400, 300)), $spec, null, $this->upload($this->canvas(300, 300)));
            $this->fail('overlay другого размера приниматься не должен');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('не совпадает', $e->getMessage());
        }

        // Водяной знак за краем фона — можно; overlay того же размера — принят.
        $template = $store->store($course->id, null, $this->upload($this->canvas(400, 300)), $spec, null, $this->upload($this->canvas(400, 300)));
        $this->assertNotNull($template->overlay_path);
        Storage::disk('public')->assertExists($template->overlay_path);
    }

    public function test_small_tracking_does_not_squeeze_the_line(): void
    {
        // Регрессия 22-09-2026: при трекинге шаг пера брался по ширине чернил глифа,
        // и дата Кочергиной выходила на 16 % уже макета. Трекинг −1 % кегля почти
        // не меняет длину строки.
        $width = function (float $tracking): int {
            $template = $this->template(['date' => [
                'x' => 10, 'y' => 100, 'w' => 380, 'h' => 80, 'size_px' => 48,
                'font' => 'Missing.ttf', 'color' => '#000000', 'tracking' => $tracking, 'format' => 'DD.MM.YYYY',
            ]]);
            $image = $this->render($template, ['date' => '26.01.2026', 'number' => '']);
            $xs = [];
            for ($x = 0; $x < 400; $x++) {
                for ($y = 100; $y < 180; $y += 2) {
                    [$r] = $this->rgbAt($image, $x, $y);
                    if ($r < 100) {
                        $xs[] = $x;
                        break;
                    }
                }
            }

            return max($xs) - min($xs);
        };

        $plain = $width(0.0);
        $tracked = $width(-0.5);
        $this->assertGreaterThan(150, $plain);
        $this->assertEqualsWithDelta($plain, $tracked, $plain * 0.03);
    }

    public function test_spec_rejects_unknown_blend_and_source(): void
    {
        $store = app(LessonBannerTemplateStore::class);

        foreach ([['blend' => 'multiply'], ['source' => 'title']] as $bad) {
            try {
                $store->store($this->course()->id, null, $this->upload($this->canvas(400, 300)), $this->specJson([
                    'extra' => ['x' => 0, 'y' => 0, 'w' => 100, 'h' => 50, 'size_px' => 20, 'format' => '{N}'] + $bad,
                ]));
                $this->fail('spec с '.json_encode($bad).' приниматься не должен');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, mixed> */
    private function badgeField(): array
    {
        return [
            'x' => 100, 'y' => 100, 'w' => 80, 'h' => 80, 'align' => 'center',
            'font' => 'Missing.ttf', 'size_px' => 50, 'color' => sprintf('#%02X%02X%02X', ...self::BADGE),
            'badge' => ['shape' => 'circle', 'diameter' => 80], 'format' => '{N}',
        ];
    }

    /** @param  array<string, array<string, mixed>>  $fields */
    private function template(array $fields, ?string $overlay = null): LessonBannerTemplate
    {
        Storage::disk('public')->put('tpl/bg.png', $this->png($this->canvas(400, 300)));

        $base = ['x' => 10, 'y' => 250, 'w' => 200, 'h' => 40, 'size_px' => 24, 'font' => 'Missing.ttf', 'color' => '#FFFFFF'];

        return LessonBannerTemplate::create([
            'course_id' => $this->course()->id,
            'background_disk' => 'public',
            'background_path' => 'tpl/bg.png',
            'overlay_disk' => $overlay ? 'public' : null,
            'overlay_path' => $overlay,
            'width' => 400,
            'height' => 300,
            'spec' => ['fields' => $fields + [
                'date' => $base + ['format' => 'DD.MM.YYYY'],
                'number' => $base + ['y' => 10, 'format' => 'Занятие {N}'],
            ]],
            'version' => 1,
        ]);
    }

    /** @param  array<string, array<string, mixed>>  $extra */
    private function specJson(array $extra): string
    {
        $base = ['x' => 10, 'y' => 250, 'w' => 200, 'h' => 40, 'size_px' => 24];

        return (string) json_encode(['fields' => [
            'date' => $base + ['format' => 'DD.MM.YYYY'],
            'number' => $base + ['y' => 10, 'format' => 'Занятие {N}'],
        ] + $extra], JSON_UNESCAPED_UNICODE);
    }

    /** @param  array<string, string>  $texts */
    private function render(LessonBannerTemplate $template, array $texts): \GdImage
    {
        $image = imagecreatefromstring(app(LessonBannerRenderer::class)->render($template, $texts));
        $this->assertInstanceOf(\GdImage::class, $image);

        return $image;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgbAt(\GdImage $image, int $x, int $y, bool $exact = false): array
    {
        $c = imagecolorat($image, $x, $y);
        $rgb = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];

        // JPEG сдвигает цвета на пару единиц — «точное» сравнение с допуском 6.
        return $exact ? array_map(fn (int $v, int $want): int => abs($v - $want) <= 6 ? $want : $v, $rgb, [20, 40, 200]) : $rgb;
    }

    private function canvas(int $w, int $h): \GdImage
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...self::BG));

        return $image;
    }

    private function png(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function upload(\GdImage $image): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lb').'.png';
        imagesavealpha($image, true);
        imagepng($image, $path);

        return new UploadedFile($path, basename($path), 'image/png', null, true);
    }

    private function course(): Course
    {
        $teacher = Teacher::create(['name' => 'Препод']);

        return Course::create(['title' => 'Курс', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
    }
}
