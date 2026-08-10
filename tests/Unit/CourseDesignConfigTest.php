<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CourseDesignAsset;
use Tests\TestCase;

/**
 * Страж конфига «Дизайн курсов».
 *
 * Главное здесь — первый тест. Livewire валидирует размер загрузки РАНЬШЕ, чем
 * maxSize() конкретного поля, поэтому поле с лимитом выше LIVEWIRE_UPLOAD_MAX_KB
 * молча режет файлы: человек видит «загрузка не удалась» без причины, и по
 * симптомам это не диагностируется. Пусть падает тест, а не прод.
 */
class CourseDesignConfigTest extends TestCase
{
    /** @test */
    public function upload_limits_stay_below_the_global_livewire_cap(): void
    {
        $cap = (int) env('LIVEWIRE_UPLOAD_MAX_KB', 102400);

        $this->assertLessThanOrEqual($cap, (int) config('design_assets.max_psd_kb'), 'PSD-лимит выше потолка Livewire — загрузки будут молча резаться');
        $this->assertLessThanOrEqual($cap, (int) config('design_assets.max_image_kb'), 'Лимит картинки выше потолка Livewire');
    }

    /** @test */
    public function every_configured_format_parses_into_a_ratio(): void
    {
        $formats = (array) config('design_assets.formats');

        $this->assertNotEmpty($formats, 'без форматов страница показывает пустую матрицу');

        foreach ($formats as $format) {
            $this->assertNotNull(
                CourseDesignAsset::expectedRatio((string) $format),
                "формат «{$format}» не разбирается в соотношение сторон — сверка пропорции для него молча отключится",
            );
            $this->assertStringNotContainsString(':', CourseDesignAsset::slugForFormat((string) $format), 'двоеточие ломает имена файлов и записи ZIP');
        }
    }

    /** @test */
    public function the_three_required_formats_are_configured(): void
    {
        $formats = (array) config('design_assets.formats');

        foreach (['16:9', '9:16', '4:3'] as $required) {
            $this->assertContains($required, $formats);
        }
    }

    /** @test */
    public function the_psd_disk_is_not_the_public_one(): void
    {
        $this->assertNotSame(
            config('design_assets.image_disk'),
            config('design_assets.psd_disk'),
            'исходники не должны лежать на публичном диске рядом с картинками',
        );
        $this->assertNotSame('public', config('design_assets.psd_disk'));
    }
}
