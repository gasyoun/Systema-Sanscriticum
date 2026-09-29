<?php

declare(strict_types=1);

namespace App\Services\Materials;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpZip\Constants\ZipCompressionMethod;
use PhpZip\ZipFile;
use RuntimeException;
use Throwable;

/**
 * ZIP со стенограммами уроков, разложенными по папкам курсов.
 *
 * Зачем: витрина «Материалы уроков» показывает, у кого стенограмма есть, но
 * забрать их можно было только по одной через кабинет. 187 файлов на проде
 * (22-09-2026) руками не выгрузишь.
 *
 * nelexa/zip, а не ZipArchive — он корректно держит UTF-8 в именах записей, а
 * названия курсов и уроков кириллические (тот же довод, что у
 * CourseDesignArchiver и CourseMaterialsArchiver).
 *
 * Архив собирается во ВРЕМЕННЫЙ ПРИВАТНЫЙ каталог и удаляется маршрутом после
 * отправки: стенограмма — это платная лекция целиком, её нельзя класть на
 * публичный диск даже под случайным именем.
 */
class TranscriptArchiver
{
    /** Куда складываем собранные архивы (приватно, вне public-диска). */
    private const TMP_DIR = 'app/tmp/transcript-archives';

    /** Диски, на которых лежат файлы стенограмм (см. GatedAssetController). */
    private const DISKS = ['local', 'public'];

    /**
     * Собрать архив и вернуть АБСОЛЮТНЫЙ путь к нему.
     *
     * @param  Course|null  $course  только этот курс; null — все курсы
     *
     * @throws RuntimeException если ни одной стенограммы не нашлось
     */
    public function build(?Course $course = null): string
    {
        $lessons = $this->lessons($course);

        $dir = storage_path(self::TMP_DIR);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Не удалось создать временную директорию для архива.');
        }

        $this->purgeStale($dir);

        $archivePath = $dir.DIRECTORY_SEPARATOR.sprintf('transcripts-%s.zip', Str::random(24));
        $zip = new ZipFile;
        $added = 0;
        $missing = [];
        $contents = [];

        try {
            foreach ($lessons->groupBy('course_id') as $courseLessons) {
                /** @var Lesson $first */
                $first = $courseLessons->first();
                $folder = $this->folder($first->course);
                $lines = [];

                foreach ($courseLessons as $lesson) {
                    $absolute = $this->absolute((string) $lesson->transcript_file);
                    if ($absolute === null) {
                        // Файл записан в уроке, но на дисках его нет — это не повод
                        // ронять всю выгрузку, но и молчать о нём нельзя.
                        $missing[] = $folder.' — урок #'.$lesson->id.' «'.$lesson->title.'»: '.$lesson->transcript_file;

                        continue;
                    }

                    $entry = $folder.'/'.$lesson->transcriptDownloadName();
                    $zip->addFile($absolute, $entry, ZipCompressionMethod::DEFLATED);
                    $added++;
                    $lines[] = '  '.$lesson->transcriptDownloadName().' — урок #'.$lesson->id.' «'.$lesson->title.'»';
                }

                if ($lines !== []) {
                    $contents[] = ($first->course?->title ?? 'Без курса').' ['.$folder.']'."\n".implode("\n", $lines);
                }
            }

            if ($added === 0) {
                $zip->close();

                throw new RuntimeException('Стенограмм не нашлось — архив собирать не из чего.');
            }

            $zip->addFromString('Содержание.txt', $this->manifest($contents, $missing, $added), ZipCompressionMethod::DEFLATED);

            $zip->saveAsFile($archivePath);
            $zip->close();
        } catch (Throwable $e) {
            @unlink($archivePath);

            throw $e;
        }

        return $archivePath;
    }

    /** Имя файла, которое увидит скачивающий. */
    public function downloadName(?Course $course = null): string
    {
        $scope = $course === null
            ? 'all'
            : (Str::slug($course->slug ?: (string) $course->id) ?: (string) $course->id);

        return 'transcripts-'.$scope.'-'.now()->format('Y-m-d').'.zip';
    }

    /** Сколько стенограмм уйдёт в архив (для подписи кнопки). */
    public function countAvailable(?Course $course = null): int
    {
        return $this->lessons($course)->count();
    }

    /** @return Collection<int, Lesson> */
    private function lessons(?Course $course): Collection
    {
        return Lesson::query()
            ->withTranscript()
            ->when($course !== null, fn ($q) => $q->where('course_id', $course->id))
            ->with('course:id,title,slug')
            ->orderBy('course_id')
            ->orderBy('block_number')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Папка курса внутри архива. Латинский slug курса — кириллица в именах
     * записей ZIP живёт, но распаковка такого архива на чужой машине зависит от
     * кодировки, а slug у курсов и так латинский.
     */
    private function folder(?Course $course): string
    {
        if ($course === null) {
            return 'bez-kursa';
        }

        return Str::slug($course->slug ?: (string) $course->id) ?: 'course-'.$course->id;
    }

    /**
     * @param  list<string>  $contents
     * @param  list<string>  $missing
     */
    private function manifest(array $contents, array $missing, int $added): string
    {
        $text = "Стенограммы уроков\n".now()->format('d.m.Y H:i')." — файлов: {$added}\n\n".implode("\n\n", $contents)."\n";

        if ($missing !== []) {
            $text .= "\n\nФайл записан в уроке, но не найден на дисках:\n".implode("\n", $missing)."\n";
        }

        return $text;
    }

    private function absolute(string $path): ?string
    {
        if ($path === '' || preg_match('#^https?://#i', $path) || str_starts_with($path, '/')) {
            // Абсолютный URL опубликованной лекции — не наш файл (GatedAssetController).
            return null;
        }

        foreach (self::DISKS as $disk) {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                continue;
            }

            $absolute = $storage->path($path);
            if (is_file($absolute)) {
                return $absolute;
            }
        }

        return null;
    }

    /**
     * Подчистить архивы старше часа: маршрут удаляет файл после отправки, но
     * брошенная выгрузка след оставляет (тот же приём, что CourseDesignArchiver).
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
}
