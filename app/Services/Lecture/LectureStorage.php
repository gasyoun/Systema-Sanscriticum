<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use App\Models\LectureDraft;
use Illuminate\Support\Carbon;
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
        return 'lectures/'.$draft->id;
    }

    public function absoluteWorkingDir(LectureDraft $draft): string
    {
        $disk = Storage::disk(self::DISK);
        $path = $this->workingDir($draft);

        if (! $disk->exists($path)) {
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
        $rel = $this->workingDir($draft).'/raw';
        if (! $disk->exists($rel)) {
            $disk->makeDirectory($rel);
        }

        return $disk->path($rel);
    }

    public function relativePath(LectureDraft $draft, string $sub): string
    {
        return $this->workingDir($draft).'/'.ltrim($sub, '/');
    }

    public function absolutePath(LectureDraft $draft, string $sub): string
    {
        return Storage::disk(self::DISK)->path($this->relativePath($draft, $sub));
    }

    public function dataJsonAbsolute(LectureDraft $draft): string
    {
        $abs = $this->absolutePath($draft, 'data.json');
        if (! is_file($abs)) {
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

    // ==========================================
    // БЭКАПЫ data.json (Phase C — restore)
    // ==========================================
    // Бэкапы пишет LecturePatcher::applyToFile (и AI-прогон на стороне Python)
    // в backups/{ts}.json перед каждым изменением. Здесь — листинг и откат.

    /**
     * Список бэкапов (новые сверху): [['file' => 'YYYYmmdd_His.json', 'at' => Carbon], ...].
     *
     * @return list<array{file: string, label: string}>
     */
    public function listBackups(LectureDraft $draft): array
    {
        $disk = Storage::disk(self::DISK);
        $dir = $this->workingDir($draft).'/backups';
        if (! $disk->exists($dir)) {
            return [];
        }

        $files = array_filter(
            $disk->files($dir),
            fn (string $path) => str_ends_with($path, '.json'),
        );

        rsort($files); // имена — timestamp'ы, лексикографически = по времени

        return array_map(function (string $path) {
            $name = basename($path);

            return ['file' => $name, 'label' => $this->backupLabel($name)];
        }, array_values($files));
    }

    /**
     * Откатывает data.json к выбранному бэкапу. Текущее состояние предварительно
     * само бэкапится (через тот же каталог), чтобы откат был обратим.
     *
     * @throws RuntimeException при выходе за пределы backups/ или отсутствии файла
     */
    public function restoreBackup(LectureDraft $draft, string $backupFile): void
    {
        // Защита от обхода каталога: только «basename.json» из backups/.
        if (! preg_match('/^[0-9A-Za-z_.\-]+\.json$/', $backupFile) || str_contains($backupFile, '..')) {
            throw new RuntimeException("Недопустимое имя бэкапа: {$backupFile}");
        }

        $disk = Storage::disk(self::DISK);
        $backupRel = $this->workingDir($draft).'/backups/'.$backupFile;
        if (! $disk->exists($backupRel)) {
            throw new RuntimeException("Бэкап не найден: {$backupFile}");
        }

        $dataRel = $this->relativePath($draft, 'data.json');

        // Бэкапим текущее перед перезаписью (если есть что бэкапить).
        if ($disk->exists($dataRel)) {
            $disk->copy($dataRel, $this->workingDir($draft).'/backups/'.date('Ymd_His').'_pre_restore.json');
        }

        $disk->put($dataRel, $disk->get($backupRel));
    }

    /** «20260625_140530.json» → «25.06.2026 14:05:30». */
    private function backupLabel(string $name): string
    {
        if (preg_match('/^(\d{8})_(\d{6})/', $name, $m)) {
            try {
                $dt = Carbon::createFromFormat('Ymd His', $m[1].' '.$m[2]);

                return $dt->format('d.m.Y H:i:s').(str_contains($name, '_pre_restore') ? ' (перед откатом)' : '');
            } catch (\Throwable) {
                // падаем в фолбэк ниже
            }
        }

        return $name;
    }
}
