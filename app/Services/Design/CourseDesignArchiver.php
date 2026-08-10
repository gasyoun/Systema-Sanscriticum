<?php

declare(strict_types=1);

namespace App\Services\Design;

use App\Models\Course;
use App\Models\CourseDesignAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpZip\Constants\ZipCompressionMethod;
use PhpZip\ZipFile;
use RuntimeException;

/**
 * ZIP с баннерами курса: картинки всех закрытых форматов + залитые PSD +
 * текстовый список ссылок на облачные исходники.
 *
 * nelexa/zip, а не ZipArchive — он корректно держит UTF-8 в именах записей
 * (тот же довод, что у CourseMaterialsArchiver: названия курсов кириллические).
 *
 * Архив кладём во ВРЕМЕННЫЙ ПРИВАТНЫЙ каталог, а не в public/archives: тогда
 * вопрос угадывания имени не стоит вообще, а не решается длиной случайного
 * суффикса. Отдаёт файл маршрут с ролевым гейтом, он же удаляет его после
 * отправки.
 */
class CourseDesignArchiver
{
    /** Куда складываем собранные архивы (приватно, вне public-диска). */
    private const TMP_DIR = 'app/tmp/course-design-archives';

    /**
     * Собрать архив и вернуть АБСОЛЮТНЫЙ путь к нему.
     *
     * Маршрут выдачи собирает архив сам и тут же его стримит с
     * deleteFileAfterSend, поэтому наружу не уходит ни имя файла, ни токен —
     * пользовательской строки в пути не появляется вовсе.
     *
     * @throws RuntimeException если у курса нет ни одного загруженного баннера
     */
    public function build(Course $course): string
    {
        $course->loadMissing('designAssets');

        /** @var list<string> $formats */
        $formats = (array) config('design_assets.formats', []);

        $assets = $course->designAssets
            ->filter(fn (CourseDesignAsset $a): bool => in_array($a->format, $formats, true))
            ->sortBy(fn (CourseDesignAsset $a): int => array_search($a->format, $formats, true) ?: 0);

        $dir = storage_path(self::TMP_DIR);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Не удалось создать временную директорию для архива.');
        }

        $this->purgeStale($dir);

        $token = sprintf('%s-%s.zip', Str::slug($course->slug ?: 'course') ?: 'course', Str::random(24));
        $archivePath = $dir.DIRECTORY_SEPARATOR.$token;

        $zip = new ZipFile;
        $added = 0;
        $psdLinks = [];

        try {
            foreach ($assets as $asset) {
                $slug = $asset->formatSlug();

                if (filled($asset->path)) {
                    $abs = $this->absolute((string) $asset->disk, (string) $asset->path);
                    if ($abs !== null) {
                        $zip->addFile($abs, $this->entryName($course, $slug, $abs), ZipCompressionMethod::STORED);
                        $added++;
                    }
                }

                if (filled($asset->psd_path)) {
                    $abs = $this->absolute((string) $asset->psd_disk, (string) $asset->psd_path);
                    if ($abs !== null) {
                        $zip->addFile($abs, 'PSD/'.$this->entryName($course, $slug, $abs), ZipCompressionMethod::STORED);
                        $added++;
                    }
                }

                if (filled($asset->psd_url)) {
                    $psdLinks[] = $asset->format.' — '.$asset->psd_url;
                }
            }

            if ($added === 0 && $psdLinks === []) {
                $zip->close();

                throw new RuntimeException('У курса нет ни одного загруженного баннера — архив собирать не из чего.');
            }

            if ($psdLinks !== []) {
                $zip->addFromString(
                    'Исходники PSD (ссылки).txt',
                    $course->title."\n\n".implode("\n", $psdLinks)."\n",
                    ZipCompressionMethod::DEFLATED,
                );
            }

            $zip->saveAsFile($archivePath);
            $zip->close();
        } catch (\Throwable $e) {
            @unlink($archivePath);

            throw $e;
        }

        return $archivePath;
    }

    /** Имя файла, которое увидит скачивающий. */
    public function downloadName(Course $course): string
    {
        return (Str::slug($course->slug ?: (string) $course->id) ?: 'course').'-design.zip';
    }

    /**
     * Подчистить архивы старше часа.
     *
     * Маршрут выдачи удаляет файл после отправки, но незавершённая или
     * брошенная выгрузка след оставляет. Без этой уборки каталог рос бы
     * бесконечно и молча — заметили бы только по сторожу storage:check.
     */
    private function purgeStale(string $dir): void
    {
        $deadline = time() - 3600;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.zip') ?: [] as $file) {
            if (is_file($file) && (int) @filemtime($file) < $deadline) {
                @unlink($file);
            }
        }
    }

    /** Имя записи внутри архива: «sanskrit-osnovy-16x9.jpg». */
    private function entryName(Course $course, string $formatSlug, string $absolutePath): string
    {
        $ext = pathinfo($absolutePath, PATHINFO_EXTENSION);
        $base = Str::slug($course->slug ?: (string) $course->id) ?: (string) $course->id;

        return $base.'-'.$formatSlug.($ext !== '' ? '.'.$ext : '');
    }

    private function absolute(string $disk, string $path): ?string
    {
        if ($disk === '' || $path === '') {
            return null;
        }

        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return null;
        }

        $abs = $storage->path($path);

        return is_file($abs) ? $abs : null;
    }
}
