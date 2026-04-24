<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpZip\Constants\ZipCompressionMethod;
use PhpZip\ZipFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseMaterialsArchiver
{
    /**
     * Лимит размера архива — 2 ГБ.
     * Защита от создания гигантских ZIP, которые могут не докачаться.
     */
    private const MAX_ARCHIVE_SIZE_BYTES = 2 * 1024 * 1024 * 1024;

    /**
     * Кеш готового архива на 6 часов.
     */
    private const CACHE_TTL_MINUTES = 360;

    /**
     * Собирает архив материалов курса для конкретного студента.
     */
    public function buildForUser(Course $course, User $user, array $unlockedTariffs): StreamedResponse
    {
        $tariffsHash = md5(implode(',', $this->normalizeTariffs($unlockedTariffs)));
        $cacheKey = sprintf('course_materials:%d:%s', $course->id, $tariffsHash);

        $cachedPath = Cache::get($cacheKey);

        // Если кеш есть и файл на месте — отдаём его
        if ($cachedPath && file_exists($cachedPath)) {
            return $this->respondWithFile($cachedPath, $course);
        }

        // Иначе собираем заново
        $archivePath = $this->buildArchive($course, $unlockedTariffs);

        Cache::put($cacheKey, $archivePath, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $this->respondWithFile($archivePath, $course);
    }

    /**
     * Создаёт ZIP-архив через nelexa/zip — корректная UTF-8 поддержка имён.
     */
    private function buildArchive(Course $course, array $unlockedTariffs): string
{
    \Log::info('[Archiver] Старт сборки', [
        'course_id'   => $course->id,
        'course_slug' => $course->slug,
        'tariffs'     => $unlockedTariffs,
    ]);

    $course->load(['lessons' => function ($q): void {
        $q->orderBy('block_number')->orderBy('id');
    }]);

    $accessibleLessons = $course->lessons->filter(
        fn ($lesson) => $this->isLessonUnlocked($lesson, $unlockedTariffs)
    );

    if ($accessibleLessons->isEmpty()) {
        throw new \RuntimeException('У вас нет доступа ни к одному уроку этого курса.');
    }

    $tmpDir = storage_path('app/tmp/course-archives');
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        throw new \RuntimeException('Не удалось создать временную директорию');
    }

    $archivePath = $tmpDir . '/' . $course->slug . '_' . now()->format('Y-m-d_His') . '_' . uniqid() . '.zip';

    $zipFile = new ZipFile();

    try {
        $totalSize    = 0;
        $filesAdded   = 0;
        $courseFolder = $this->sanitizeFolderName($course->title);

        foreach ($accessibleLessons->values() as $index => $lesson) {
            if (empty($lesson->attachments) || !is_array($lesson->attachments)) {
                continue;
            }

            $lessonNum    = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $lessonFolder = $courseFolder . '/Урок ' . $lessonNum . ' - ' . $this->sanitizeFolderName($lesson->title);

            foreach ($lesson->attachments as $relativePath) {
                $absolutePath = Storage::disk('public')->path($relativePath);

                if (!is_file($absolutePath)) {
                    continue;
                }

                $fileSize = filesize($absolutePath) ?: 0;

                if ($totalSize + $fileSize > self::MAX_ARCHIVE_SIZE_BYTES) {
                    $zipFile->close();
                    @unlink($archivePath);
                    throw new \RuntimeException(
                        'Размер архива превысил 2 ГБ. Скачайте материалы поурочно.'
                    );
                }

                $fileName  = basename($relativePath);
                $entryName = $lessonFolder . '/' . $fileName;

                $compressionMethod = $this->shouldCompress($fileName)
                    ? ZipCompressionMethod::DEFLATED
                    : ZipCompressionMethod::STORED;

                $zipFile->addFile($absolutePath, $entryName, $compressionMethod);

                $totalSize += $fileSize;
                $filesAdded++;
            }
        }

        // --- НОВОЕ: добавляем текстовый файл со ссылками на видео ---
        $videoLinksContent = $this->buildVideoLinksFile($course, $accessibleLessons);
        $hasVideoLinks     = $videoLinksContent !== null;

        if ($hasVideoLinks) {
            $zipFile->addFromString(
                $courseFolder . '/Ссылки на видео.txt',
                $videoLinksContent,
                ZipCompressionMethod::DEFLATED
            );
        }

        // Падаем только если нет ни файлов, ни ссылок
        if ($filesAdded === 0 && !$hasVideoLinks) {
            $zipFile->close();
            @unlink($archivePath);
            throw new \RuntimeException('У уроков курса пока нет ни материалов, ни ссылок на видео.');
        }

        $zipFile->saveAsFile($archivePath);
        $zipFile->close();
    } catch (\Throwable $e) {
        $zipFile->close();
        if (file_exists($archivePath)) {
            @unlink($archivePath);
        }
        throw $e;
    }

    \Log::info('[Archiver] Архив собран', [
        'path'        => $archivePath,
        'size_bytes'  => file_exists($archivePath) ? filesize($archivePath) : 0,
        'files_count' => $filesAdded,
    ]);

    return $archivePath;
}

/**
 * Собирает текстовый файл со ссылками на видео уроков, сгруппированный по блокам.
 * Возвращает null, если ни одной ссылки нет.
 */
private function buildVideoLinksFile(Course $course, \Illuminate\Support\Collection $lessons): ?string
{
    /** @var VideoLinkNormalizer $normalizer */
    $normalizer = app(VideoLinkNormalizer::class);

    // Группируем уроки по блокам, сохраняя порядок
    $byBlock = $lessons->groupBy(fn ($l) => (int) ($l->block_number ?? 0))
        ->sortKeys();

    $lines   = [];
    $lines[] = 'Курс: ' . $course->title;
    $lines[] = 'Сгенерировано: ' . now()->format('d.m.Y H:i');
    $lines[] = str_repeat('=', 60);
    $lines[] = '';

    $hasAnyLink = false;

    foreach ($byBlock as $blockNumber => $blockLessons) {
        $blockTitle = $blockNumber > 0
            ? 'Блок ' . $blockNumber
            : 'Без блока';

        $lines[] = '### ' . $blockTitle . ' ###';
        $lines[] = '';

        $lessonIndex = 0;

        foreach ($blockLessons as $lesson) {
            $lessonIndex++;

            // Берём ссылки из всех возможных полей, нормализуем, убираем дубли
            $youtube = $normalizer->youtube($lesson->youtube_url)
                ?? $normalizer->youtube($lesson->video_url ?? null);

            $rutube = $normalizer->rutube($lesson->rutube_url);

            if ($youtube === null && $rutube === null) {
                continue;
            }

            $hasAnyLink = true;

            $lines[] = sprintf('Урок %02d: %s', $lessonIndex, $lesson->title);

            if ($youtube !== null) {
                $lines[] = 'YouTube: ' . $youtube;
            }
            if ($rutube !== null) {
                $lines[] = 'Rutube:  ' . $rutube;
            }

            $lines[] = '';
        }

        $lines[] = '';
    }

    if (!$hasAnyLink) {
        return null;
    }

    // BOM для корректного отображения кириллицы в Windows Notepad
    return "\xEF\xBB\xBF" . implode("\r\n", $lines);
}

    /**
     * Стоит ли сжимать файл по расширению.
     * Уже сжатые форматы (PDF/MP3/MP4/ZIP/JPG) повторно жать бессмысленно — только время.
     */
    private function shouldCompress(string $fileName): bool
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $alreadyCompressed = [
            'pdf', 'zip', 'rar', '7z', 'gz', 'tar',
            'mp3', 'mp4', 'mov', 'webm', 'm4a', 'aac', 'ogg', 'wav',
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'docx', 'xlsx', 'pptx', // тоже zip-based
        ];

        return !in_array($ext, $alreadyCompressed, true);
    }

    /**
     * Доступен ли урок при данном наборе тарифов.
     */
    private function isLessonUnlocked($lesson, array $unlockedTariffs): bool
    {
        if (in_array('full', $unlockedTariffs, true)) {
            return true;
        }

        return in_array('block_' . $lesson->block_number, $unlockedTariffs, true);
    }

    /**
     * Стабильный список тарифов для хеша кеша.
     */
    private function normalizeTariffs(array $tariffs): array
    {
        $tariffs = array_unique(array_filter($tariffs));
        sort($tariffs);

        return $tariffs;
    }

    /**
     * Чистит имя папки от недопустимых символов.
     * Кириллица сохраняется — nelexa/zip корректно её сериализует с UTF-8.
     */
    private function sanitizeFolderName(string $name): string
    {
        $name = preg_replace('#[\\\\/:*?"<>|]#u', '-', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = preg_replace('/-{2,}/', '-', $name);

        return trim($name, ' -');
    }

    /**
     * Отдаём готовый ZIP пользователю через стрим.
     * StreamedResponse лучше readfile()/file_get_contents() для больших файлов —
     * не буферизует контент в PHP-памяти.
     */
    private function respondWithFile(string $path, Course $course): StreamedResponse
    {
        
        \Log::info('[Archiver] Отдаём файл', [
        'path' => $path,
        'exists' => file_exists($path),
        'size'   => file_exists($path) ? filesize($path) : 0,
    ]);
    
        $downloadName = sprintf(
            'materials_%s_%s.zip',
            $course->slug,
            now()->format('Y-m-d')
        );

        $fileSize = filesize($path);

        return response()->stream(function () use ($path): void {
            if (ob_get_level()) {
                ob_end_clean();
            }

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }

            while (!feof($handle)) {
                echo fread($handle, 1024 * 1024); // 1 МБ за раз
                flush();
            }

            fclose($handle);
        }, 200, [
            'Content-Type'              => 'application/zip',
            'Content-Disposition'       => 'attachment; filename="' . $downloadName . '"',
            'Content-Length'            => (string) $fileSize,
            'Cache-Control'             => 'no-cache, no-store, must-revalidate',
            'Pragma'                    => 'no-cache',
            'Expires'                   => '0',
            'X-Accel-Buffering'         => 'no',
            'Content-Transfer-Encoding' => 'binary',
        ]);
    }
}