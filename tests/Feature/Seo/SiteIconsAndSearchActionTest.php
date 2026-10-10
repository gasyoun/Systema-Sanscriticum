<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Course;
use App\Models\CourseBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6211 — остатки SEO-аудита H6160.
 *
 * Low-1: публичные проиндексированные лейауты отдают PNG-иконку 512×512,
 * apple-touch-icon и публичный манифест (иконка в мобильной выдаче Google;
 * раньше был только favicon.ico). Манифест отдельный от кабинетного
 * manifest.webmanifest — у кабинета свой PWA-контракт (см.
 * PwaManifestIconsTest).
 *
 * Low-3: SearchAction на главной ведёт на канонический path-URL
 * /online/poisk/{слова}, а не на /online?search=…, который 301-редиректится.
 *
 * Medium-2 (первая порция): на recorded-страницах рендерится блок «Запись
 * потока» из реальных фактов когорты (период по блокам, число занятий,
 * модули, уровень). Фактов нет — блока нет.
 */
class SiteIconsAndSearchActionTest extends TestCase
{
    use RefreshDatabase;

    /** Публичные проиндексированные лейауты: главная, каталог/курсы, промо, словарь, статьи. */
    private const PUBLIC_LAYOUTS = [
        'main.blade.php',
        'layouts/shop.blade.php',
        'layouts/promo.blade.php',
        'layouts/slovar.blade.php',
        'layouts/articles.blade.php',
    ];

    /** @test */
    public function public_layouts_link_png_icon_apple_touch_and_site_manifest(): void
    {
        foreach (self::PUBLIC_LAYOUTS as $layout) {
            $html = file_get_contents(resource_path('views/'.$layout));

            $this->assertStringContainsString(
                'rel="icon" type="image/png" sizes="512x512"',
                $html,
                "{$layout}: нет PNG-иконки 512 (Low-1, иконка в мобильной выдаче)."
            );
            $this->assertStringContainsString(
                'rel="apple-touch-icon" sizes="180x180"',
                $html,
                "{$layout}: нет apple-touch-icon."
            );
            $this->assertStringContainsString(
                'rel="manifest"',
                $html,
                "{$layout}: нет ссылки на манифест."
            );
            $this->assertStringContainsString(
                'manifest-site.webmanifest',
                $html,
                "{$layout}: должен ссылаться на публичный манифест сайта, не кабинетный."
            );
        }
    }

    /** @test */
    public function public_site_manifest_declares_real_icons(): void
    {
        $path = public_path('manifest-site.webmanifest');
        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($manifest, 'manifest-site.webmanifest — не валидный JSON');

        $this->assertSame('/', $manifest['start_url'] ?? null, 'Публичный манифест стартует с главной, не из кабинета.');

        foreach ($manifest['icons'] ?? [] as $icon) {
            $file = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($file, "Иконки из манифеста нет на диске: {$icon['src']}");

            if (($icon['type'] ?? null) !== 'image/png') {
                continue; // .ico без IHDR, размер не проверяем
            }

            $handle = fopen($file, 'rb');
            $header = fread($handle, 24);
            fclose($handle);
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($header, 0, 8), "Не PNG: {$icon['src']}");
            $size = unpack('Nwidth/Nheight', substr($header, 16, 8));

            $this->assertSame(
                $icon['sizes'],
                "{$size['width']}x{$size['height']}",
                "{$icon['src']}: declared sizes={$icon['sizes']}, файл {$size['width']}x{$size['height']}."
            );
        }

        $sizes = array_column($manifest['icons'] ?? [], 'sizes');
        $this->assertContains('512x512', $sizes, 'В манифесте нет иконки 512×512 — её и просит Low-1.');
    }

    /** @test */
    public function homepage_searchaction_targets_canonical_poisk_path(): void
    {
        $html = file_get_contents(resource_path('views/main.blade.php'));

        $this->assertStringContainsString(
            '/online/poisk/{search_term_string}',
            $html,
            'SearchAction должен вести на канонический /online/poisk/…, а не на ?search= с 301-хопом.'
        );
        $this->assertStringNotContainsString(
            '/online?search={search_term_string}',
            $html,
            'Легаси ?search= таргет вернулся в SearchAction.'
        );
    }

    /** @test */
    public function recorded_course_page_renders_cohort_block_from_real_facts(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'title' => 'Грамматика по Кочергиной гр.42',
            'format' => 'recorded',
            'lessons_count' => 64,
        ]);

        CourseBlock::create([
            'course_id' => $course->id,
            'number' => 1,
            'title' => 'Деванагари и сандхи',
            'starts_at' => '2021-09-03 10:00:00',
            'ends_at' => '2022-05-20 12:00:00',
        ]);
        CourseBlock::create([
            'course_id' => $course->id,
            'number' => 2,
            'title' => 'Именные основы на -a',
            'starts_at' => '2022-09-05 10:00:00',
            'ends_at' => '2023-05-25 12:00:00',
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        $response->assertSee('Запись потока гр.42');
        $response->assertSee('64 онлайн-занятия');
        $response->assertSee('Программа — 2 модуля');
        // Реальные даты потока уходят в текст (Carbon-локаль ru).
        $response->assertSee('03 сентября 2021');
        // Перелинковка на живые группы и каталог записей — path-URL, без ?format=.
        $response->assertSee('/online/format/live');
        $response->assertSee('/online/format/recorded');
    }

    /** @test */
    public function recorded_course_without_block_dates_renders_no_cohort_block(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'title' => 'Записи без дат',
            'format' => 'recorded',
            'lessons_count' => 10,
        ]);

        CourseBlock::create([
            'course_id' => $course->id,
            'number' => 1,
            'title' => 'Модуль без дат',
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        // Нет реальных дат потока — нет и выдуманных фактов: блок не рендерится.
        $response->assertDontSee('Запись потока');
    }

    /** @test */
    public function live_course_page_renders_no_cohort_block(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'title' => 'Живой курс',
            'format' => 'live',
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        $response->assertDontSee('Запись потока');
    }
}
