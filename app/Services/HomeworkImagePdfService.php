<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HomeworkComment;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Собирает все студенческие картинки одной сдачи в один PDF для проверки.
 *
 * Студент по-прежнему шлёт фото с телефона; для куратора (и письма)
 * появляется один combined-images.pdf. Оригиналы на диске не трогаем.
 */
class HomeworkImagePdfService
{
    public const DISK = 'local';

    public const FILENAME = 'combined-images.pdf';

    /** Потолок вложения в письмо проверяющему (байты). Больше — только ссылка. */
    public const MAIL_ATTACH_MAX_BYTES = 12 * 1024 * 1024;

    public function pathFor(HomeworkSubmission $submission): string
    {
        return sprintf(
            'homework/%d/%d/%s',
            (int) $submission->user_id,
            (int) $submission->lesson_id,
            self::FILENAME,
        );
    }

    public function downloadName(HomeworkSubmission $submission): string
    {
        $student = Str::slug($submission->user?->name ?: 'student', '_');
        $lesson = Str::slug($submission->lesson?->title ?: ('lesson-'.$submission->lesson_id), '_');

        return "dz_{$student}_{$lesson}_images.pdf";
    }

    public function exists(HomeworkSubmission $submission): bool
    {
        return Storage::disk(self::DISK)->exists($this->pathFor($submission));
    }

    public function sizeBytes(HomeworkSubmission $submission): ?int
    {
        if (! $this->exists($submission)) {
            return null;
        }

        return (int) Storage::disk(self::DISK)->size($this->pathFor($submission));
    }

    public function canAttachToMail(HomeworkSubmission $submission): bool
    {
        $size = $this->sizeBytes($submission);

        return $size !== null && $size > 0 && $size <= self::MAIL_ATTACH_MAX_BYTES;
    }

    /**
     * Все студенческие image-файлы сдачи (все submission-комментарии),
     * в хронологическом порядке комментария, затем id файла.
     *
     * @return Collection<int, HomeworkFile>
     */
    public function studentImageFiles(HomeworkSubmission $submission): Collection
    {
        $submission->loadMissing('comments.files');

        return $submission->comments
            ->filter(fn (HomeworkComment $c) => $c->author_role === HomeworkComment::ROLE_STUDENT
                && $c->type === HomeworkComment::TYPE_SUBMISSION)
            ->sortBy(['created_at', 'id'])
            ->flatMap(fn (HomeworkComment $c) => $c->files
                ->filter(fn (HomeworkFile $f) => $this->isRasterImage($f))
                ->sortBy('id')
                ->values())
            ->values();
    }

    /**
     * Пересобрать PDF из текущих картинок. Нет картинок → удалить старый PDF.
     *
     * @return string|null относительный path на диске, или null если PDF нет
     */
    public function rebuild(HomeworkSubmission $submission): ?string
    {
        $path = $this->pathFor($submission);
        $images = $this->studentImageFiles($submission);

        if ($images->isEmpty()) {
            $this->delete($submission);

            return null;
        }

        $pages = [];
        foreach ($images as $file) {
            $dataUri = $this->dataUriFor($file);
            if ($dataUri === null) {
                Log::info('HomeworkImagePdf: skip unreadable image', [
                    'file_id' => $file->id,
                    'mime' => $file->mime,
                    'path' => $file->path,
                ]);

                continue;
            }
            $pages[] = [
                'data_uri' => $dataUri,
                'name' => $file->original_name,
            ];
        }

        if ($pages === []) {
            $this->delete($submission);

            return null;
        }

        $pdf = Pdf::loadView('pdf.homework-images', [
            'pages' => $pages,
            'student' => $submission->user?->name,
            'lesson' => $submission->lesson?->title,
            'course' => $submission->course?->title,
        ]);

        $pdf->setPaper('a4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'dpi' => 96,
        ]);

        Storage::disk(self::DISK)->put($path, $pdf->output());

        return $path;
    }

    /**
     * Как rebuild, но ошибки не пробивают сдачу — только лог.
     */
    public function rebuildQuietly(HomeworkSubmission $submission): ?string
    {
        try {
            return $this->rebuild($submission);
        } catch (\Throwable $e) {
            Log::warning('HomeworkImagePdf rebuild failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function delete(HomeworkSubmission $submission): void
    {
        $path = $this->pathFor($submission);
        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function isRasterImage(HomeworkFile $file): bool
    {
        $mime = strtolower((string) $file->mime);
        if (str_starts_with($mime, 'image/')) {
            // SVG в PDF через data-uri непредсказуем — не берём.
            return ! str_contains($mime, 'svg');
        }

        $ext = strtolower(pathinfo((string) $file->original_name, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp'], true);
    }

    private function dataUriFor(HomeworkFile $file): ?string
    {
        $disk = $file->disk ?: self::DISK;
        if (! Storage::disk($disk)->exists($file->path)) {
            return null;
        }

        $bytes = Storage::disk($disk)->get($file->path);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = strtolower((string) ($file->mime ?: 'image/jpeg'));
        $converted = $this->normalizeToDompdfImage($bytes, $mime);
        if ($converted === null) {
            return null;
        }

        return 'data:'.$converted['mime'].';base64,'.base64_encode($converted['bytes']);
    }

    /**
     * dompdf уверенно ест jpeg/png; heic/webp/прочее — через imagick/GD если есть.
     *
     * @return array{bytes: string, mime: string}|null
     */
    private function normalizeToDompdfImage(string $bytes, string $mime): ?array
    {
        $mime = strtolower($mime);

        if (in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'], true)) {
            return ['bytes' => $bytes, 'mime' => $mime === 'image/jpg' ? 'image/jpeg' : $mime];
        }

        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick;
                $im->readImageBlob($bytes);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);

                return ['bytes' => $im->getImageBlob(), 'mime' => 'image/jpeg'];
            } catch (\Throwable) {
                // fall through to GD
            }
        }

        if (function_exists('imagecreatefromstring')) {
            $gd = @imagecreatefromstring($bytes);
            if ($gd !== false) {
                ob_start();
                imagejpeg($gd, null, 85);
                $jpeg = (string) ob_get_clean();
                imagedestroy($gd);
                if ($jpeg !== '') {
                    return ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
                }
            }
        }

        // Последняя попытка: отдать как есть (webp иногда проходит).
        if (str_starts_with($mime, 'image/')) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        return null;
    }
}
