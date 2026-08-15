<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CourseDesignAsset;
use App\Services\Design\CourseDesignAssetService;
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

    /**
     * Формат размера один на страницу и на виджет — иначе колонка строки и
     * карточка «Занято на диске» округляли бы по-разному и это читалось бы как
     * расхождение данных.
     *
     * @test
     */
    public function bytes_are_formatted_readably(): void
    {
        $this->assertSame('—', CourseDesignAssetService::formatBytes(0));
        $this->assertSame('6 КБ', CourseDesignAssetService::formatBytes(6 * 1024));
        $this->assertSame('1,5 МБ', CourseDesignAssetService::formatBytes((int) (1.5 * 1024 * 1024)));
        $this->assertSame('2,00 ГБ', CourseDesignAssetService::formatBytes(2 * 1024 * 1024 * 1024));
    }

    /**
     * Диск обложек задан отдельным ключом, а не унаследован от
     * filesystems.default — значит его легко опечатать, и тогда перенос будет
     * молча отвечать «файл не найден» на каждом курсе.
     *
     * @test
     */
    public function the_cover_disk_is_a_configured_filesystem(): void
    {
        $disk = (string) config('design_assets.cover_disk');

        $this->assertNotSame('', $disk);
        $this->assertNotEmpty(
            config('filesystems.disks.'.$disk),
            "диск «{$disk}» не описан в config/filesystems.php — перенос обложек не найдёт ни одного файла",
        );
    }

    /** @test */
    public function the_import_quality_stays_in_a_sane_range(): void
    {
        $this->assertGreaterThanOrEqual(60, (int) config('design_assets.import_jpeg_quality'), 'ниже 60 на кириллице в баннере лезут артефакты');
        $this->assertLessThanOrEqual(100, (int) config('design_assets.import_jpeg_quality'));

        $this->assertGreaterThanOrEqual(60, (int) config('design_assets.import_webp_quality'));
        $this->assertLessThanOrEqual(100, (int) config('design_assets.import_webp_quality'));

        // Сжатие PNG — это 0…9 у GD, всё остальное он трактует непредсказуемо.
        $this->assertGreaterThanOrEqual(0, (int) config('design_assets.import_png_compression'));
        $this->assertLessThanOrEqual(9, (int) config('design_assets.import_png_compression'));

        $this->assertGreaterThanOrEqual(0.0, (float) config('design_assets.import_copy_tolerance'));
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
