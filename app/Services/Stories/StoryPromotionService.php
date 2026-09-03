<?php

declare(strict_types=1);

namespace App\Services\Stories;

use App\Models\HomeworkFile;
use App\Models\StoryPost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Кураторский источник «домашняя работа → сториз» (H3964, юнит 5).
 *
 * Вызывается из Filament-экшена «В сториз» в HomeworkSubmissionResource:
 * куратор выбирает медиа-файл ПРИНЯТОЙ работы и подпись — сервис копирует
 * файл в стор-лейн telegram-story и заводит ЧЕРНОВИК story_posts
 * (source=homework, lane=persona). Никакой автоматизации: ни авто-approva,
 * ни DM-автоимпорта.
 *
 * ПУБЛИКАЦИЯ студенческих медиа отдельно гейтится визой MG на правило
 * анонимизации (features.telegram_story_student_media_visa, default OFF;
 * ASK_BATCH_CONTENT_FACTORY_TELEGRAM_2026 §2.8 — запрет публиковать лица/
 * имена студентов): издатель скипает source=homework до визы. Этот сервис
 * НЕ заменяет визу — он только кладёт заготовку на кураторский стол.
 */
class StoryPromotionService
{
    /** Куда копируются отобранные файлы (media_path становится абсолютным). */
    public function mediaDirectory(): string
    {
        return storage_path('app/telegram-story/media');
    }

    /**
     * @throws RuntimeException когда файл не медиа/не читается/уже заведён
     */
    public function fromHomeworkFile(HomeworkFile $file, string $caption, ?Carbon $publishAt = null): StoryPost
    {
        // HomeworkFile::submission() — обычный метод (через comment), не Eloquent
        // relation: свойством $file->submission не читать (LogicException).
        $submission = $file->submission();
        if ($submission === null || $submission->status !== $submission::STATUS_ACCEPTED) {
            throw new RuntimeException('В сториз можно выносить медиа только принятых работ.');
        }

        if (! $file->isImage() && ! str_starts_with((string) $file->mime, 'video/')) {
            throw new RuntimeException("Файл не фото и не видео (mime={$file->mime}) — сториз не из чего.");
        }

        $kind = $file->isImage() ? StoryPost::KIND_PHOTO : StoryPost::KIND_VIDEO;
        $sourceKey = 'homework-file-'.$file->id;
        $exists = StoryPost::query()
            ->where('source', StoryPost::SOURCE_HOMEWORK)
            ->where('source_key', $sourceKey)
            ->exists();
        if ($exists) {
            throw new RuntimeException("Этот файл уже заведён в очередь (source_key={$sourceKey}).");
        }

        $copied = $this->copyIntoMediaStore($file);

        return StoryPost::query()->create([
            'kind' => $kind,
            'lane' => StoryPost::LANE_PERSONA,
            'payload' => $caption,
            'media_path' => $copied,
            'source' => StoryPost::SOURCE_HOMEWORK,
            'source_key' => $sourceKey,
            'status' => StoryPost::STATUS_DRAFT,
            'publish_at' => $publishAt,
            'journal' => now()->toDateTimeString()." source: homework-file #{$file->id} (приёмка куратором). "
                .'Публикация — только после визы на правило анонимизации.',
        ]);
    }

    /**
     * Скопировать файл работы в telegram-story стор и вернуть абсолютный
     * путь. Работы живут на локальных дисках (Storage::disk()->path);
     * удалённый диск — честный отказ, а не тихая порча.
     */
    private function copyIntoMediaStore(HomeworkFile $file): string
    {
        $disk = Storage::disk((string) $file->disk);
        if (! method_exists($disk, 'path')) {
            throw new RuntimeException("Диск {$file->disk} не локальный — копирование в стор не поддержано.");
        }

        $source = $disk->path((string) $file->path);
        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException("Файл работы не найден на диске: {$file->disk}/{$file->path}");
        }

        $dir = $this->mediaDirectory();
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог стора: {$dir}");
        }

        $ext = strtolower(pathinfo((string) $file->original_name, PATHINFO_EXTENSION)) ?: 'bin';
        $target = $dir.'/homework-'.$file->id.'.'.$ext;
        if (! @copy($source, $target)) {
            throw new RuntimeException("Не удалось скопировать {$source} → {$target}");
        }

        return $target;
    }
}
