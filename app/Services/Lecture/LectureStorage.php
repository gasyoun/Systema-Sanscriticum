<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use App\Models\LectureDraft;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Знает соглашение о раскладке файлов лекции.
 *
 *   storage/app/lectures/{draft_id}/
 *     raw/                     — загруженные исходники
 *     slides/                  — JPG (создаёт lecture-builder)
 *     data.json                — структурированные данные лекции
 *     backups/                 — бэкапы data.json
 *     output/lecture.html      — собранный HTML
 *
 * Сервис всегда оперирует абсолютными путями для передачи в Python-микросервис.
 */
class LectureStorage
{
    /** Имя диска. По умолчанию 'local' = storage/app */
    private const DISK = 'local';

    public function workingDir(LectureDraft $draft): string
    {
        return 'lectures/' . $draft->id;
    }

    public function absoluteWorkingDir(LectureDraft $draft): string
    {
        $disk = Storage::disk(self::DISK);
        $path = $this->workingDir($draft);

        if (!$disk->exists($path)) {
            $disk->makeDirectory($path);
        }

        return $disk->path($path);
    }

    /**
     * Гарантирует, что подпапка raw/ создана и возвращает её абсолютный путь.
     */
    public function ensureRawDir(LectureDraft $draft): string
    {
        $disk = Storage::disk(self::DISK);
        $rel = $this->workingDir($draft) . '/raw';
        if (!$disk->exists($rel)) {
            $disk->makeDirectory($rel);
        }
        return $disk->path($rel);
    }

    public function relativePath(LectureDraft $draft, string $sub): string
    {
        return $this->workingDir($draft) . '/' . ltrim($sub, '/');
    }

    public function absolutePath(LectureDraft $draft, string $sub): string
    {
        return Storage::disk(self::DISK)->path($this->relativePath($draft, $sub));
    }

    public function dataJsonAbsolute(LectureDraft $draft): string
    {
        $abs = $this->absolutePath($draft, 'data.json');
        if (!is_file($abs)) {
            throw new RuntimeException("data.json не существует: {$abs}");
        }
        return $abs;
    }

    /**
     * Удаляет рабочую папку черновика. Используется при удалении черновика.
     */
    public function deleteWorkingDir(LectureDraft $draft): void
    {
        Storage::disk(self::DISK)->deleteDirectory($this->workingDir($draft));
    }
}
