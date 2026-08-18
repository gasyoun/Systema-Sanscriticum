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

        $maxPages = max(0, (int) config('homework.image_pdf.max_pages', 40));
        if ($maxPages > 0 && $images->count() > $maxPages) {
            Log::warning('HomeworkImagePdf: page cap reached', [
                'submission_id' => $submission->id,
                'images' => $images->count(),
                'max_pages' => $maxPages,
            ]);
            $images = $images->take($maxPages);
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
            unset($dataUri);
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
        // Оригинал больше не нужен: до 18-08-2026 он доживал до base64 и
        // держал лишние мегабайты ровно там, где память и кончалась.
        unset($bytes);

        if ($converted === null) {
            return null;
        }

        $uri = 'data:'.$converted['mime'].';base64,'.base64_encode($converted['bytes']);
        unset($converted);

        return $uri;
    }

    /**
     * Одна страница PDF — это один jpeg, ужатый до `max_edge_px` по длинной
     * стороне и повёрнутый по EXIF.
     *
     * До 18-08-2026 jpeg/png/gif уходили в dompdf КАК ЕСТЬ, и это клало
     * php-fpm с его 128 МБ на обычной сдаче с телефона: снимок айфона —
     * 4032×3024 (~5,5 МБ), значит ~7,4 МБ строки на base64 каждой страницы
     * плюс полноразмерный декод кадра внутри dompdf (~42 МБ). Семь страниц
     * (гр.60, «Кочергина 3 (читка)», 18.08.2026) переполняли лимит.
     *
     * Почему это ломало именно СДАЧУ, а не только PDF: исчерпание памяти —
     * фатальная ошибка PHP, её не ловит `try/catch`, поэтому страховка
     * `rebuildQuietly()` не срабатывала. Падал весь POST, студент получал
     * 500 на уже сохранённой в базе работе и повторял отправку по кругу,
     * а `notifyTeacher()` за сборкой PDF не выполнялся — проверяющий о
     * работе не узнавал. Ужатие здесь и есть то, что делает обещание
     * «падение сборки не откатывает сдачу» правдой.
     *
     * @return array{bytes: string, mime: string}|null
     */
    private function normalizeToDompdfImage(string $bytes, string $mime): ?array
    {
        $mime = strtolower($mime);
        $maxEdge = max(320, (int) config('homework.image_pdf.max_edge_px', 1600));
        $quality = min(95, max(40, (int) config('homework.image_pdf.jpeg_quality', 82)));

        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick;
                $im->readImageBlob($bytes);

                // Многокадровый heic/gif: в PDF идёт первый кадр, остальные
                // только едят память.
                if ($im->getNumberImages() > 1) {
                    $im->setIteratorIndex(0);
                    $frame = $im->getImage();
                    $im->clear();
                    $im->destroy();
                    $im = $frame;
                }

                // Фото с телефона почти всегда лежит «боком» с EXIF-флагом
                // поворота. dompdf его не читает — без autoOrient страница
                // уезжает набок, и это стало бы заметно именно после ужатия.
                if (method_exists($im, 'autoOrient')) {
                    $im->autoOrient();
                }

                $im->thumbnailImage($maxEdge, $maxEdge, true, false);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality($quality);
                $im->stripImage();

                $jpeg = $im->getImageBlob();
                $im->clear();
                $im->destroy();

                if ($jpeg !== '') {
                    return ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
                }
            } catch (\Throwable) {
                // fall through to GD
            }
        }

        if (function_exists('imagecreatefromstring')) {
            $gd = @imagecreatefromstring($bytes);
            if ($gd !== false) {
                $gd = $this->downscaleGd($gd, $maxEdge);
                ob_start();
                imagejpeg($gd, null, $quality);
                $jpeg = (string) ob_get_clean();
                imagedestroy($gd);
                if ($jpeg !== '') {
                    return ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
                }
            }
        }

        // Ни imagick, ни GD не прочитали (обычно heic без делегата). Отдать
        // как есть можно только если кадр заведомо мал: несжатый оригинал
        // здесь — ровно тот путь, который и приводил к падению.
        $rawMax = max(0, (int) config('homework.image_pdf.raw_passthrough_max_kb', 512)) * 1024;
        if (str_starts_with($mime, 'image/') && strlen($bytes) <= $rawMax) {
            return ['bytes' => $bytes, 'mime' => $mime === 'image/jpg' ? 'image/jpeg' : $mime];
        }

        return null;
    }

    /**
     * GD-ветка ужатия. Отдельным методом, потому что у GD нет thumbnailImage
     * и пропорции приходится считать руками.
     *
     * @param  \GdImage  $gd
     * @return \GdImage
     */
    private function downscaleGd($gd, int $maxEdge)
    {
        $w = imagesx($gd);
        $h = imagesy($gd);
        $edge = max($w, $h);

        if ($edge <= $maxEdge || $edge === 0) {
            return $gd;
        }

        $ratio = $maxEdge / $edge;
        $small = imagescale($gd, max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)));

        if ($small === false) {
            return $gd;
        }

        imagedestroy($gd);

        return $small;
    }
}
