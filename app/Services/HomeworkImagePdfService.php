<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HomeworkComment;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Support\RasterImageMemory;
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
            $page = $this->pageFor($file);
            if ($page === null) {
                Log::info('HomeworkImagePdf: skip unreadable image', [
                    'file_id' => $file->id,
                    'mime' => $file->mime,
                    'path' => $file->path,
                ]);

                continue;
            }
            // Заглушки («страница пропущена») тоже попадают в PDF: проверяющий
            // должен видеть, что файл есть, но в сборку не влез, — а не гадать,
            // почему страниц меньше, чем файлов. Даже PDF из одних заглушек
            // собирается по той же причине.
            $pages[] = $page;
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

    /**
     * Страница PDF для одного файла.
     *
     * До 16-08-2026 jpeg/png вставлялись в PDF байт-в-байт: четыре телефонных
     * фото по ~5 МБ (base64 +33 %, три живые копии на файл, полноразмерный
     * декод в dompdf) съедали 128 МБ FPM, и студент получал 500 при уже
     * сохранённой сдаче. Теперь каждая страница даунскейлится (PDF — это
     * ревью-копия, оригиналы целы в кабинете), а неподъёмная картинка даёт
     * заглушку, а не роняет сборку.
     *
     * @return array{data_uri: string, name: ?string}|array{skipped: true, name: ?string}|null
     */
    private function pageFor(HomeworkFile $file): ?array
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
        $jpeg = $this->convertToPdfJpeg($bytes, $mime);
        unset($bytes); // исходник больше не нужен — постраничная экономия

        if ($jpeg === null) {
            return null;
        }

        if ($jpeg === self::PAGE_TOO_LARGE) {
            Log::warning('HomeworkImagePdf: page skipped, image too large for memory', [
                'file_id' => $file->id,
                'mime' => $file->mime,
                'size' => $file->size,
            ]);

            return ['skipped' => true, 'name' => $file->original_name];
        }

        return [
            'data_uri' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
            'name' => $file->original_name,
        ];
    }

    /** Маркер «картинка не влезает в память» — отличаем от «нечитаемо» (null). */
    private const PAGE_TOO_LARGE = "\0too-large";

    /** Байт на пиксель при декоде: Imagick Q16 — 4 канала × 2 байта, с запасом. */
    private const BPP_IMAGICK = 8.0 * 1.3;

    /** GD truecolor ~4-5 байт на пиксель, с запасом. */
    private const BPP_GD = 5.0 * 1.3;

    /**
     * Любой растр → даунскейленный JPEG для страницы PDF.
     *
     * Публичный шов для тестов. Возвращает jpeg-байты, PAGE_TOO_LARGE когда
     * картинка не влезает в память ни одним движком, null когда нечитаемо.
     * Через конвейер идут ВСЕ форматы, включая jpeg/png: прежний путь «отдать
     * как есть» и был причиной OOM.
     */
    public function convertToPdfJpeg(string $bytes, string $mime): ?string
    {
        $maxEdge = max(320, (int) config('homework.pdf_max_edge_px', 1600));
        $quality = min(95, max(40, (int) config('homework.pdf_jpeg_quality', 80)));

        [$width, $height] = $this->probeDimensions($bytes);
        if ($width === null || $height === null) {
            // Габариты не узнали даже по заголовку: маленький блоб пробуем
            // декодировать вслепую, большой — не рискуем памятью.
            if (strlen($bytes) > 8 * 1024 * 1024) {
                return self::PAGE_TOO_LARGE;
            }
            $width = $height = 0;
        }

        $isJpeg = in_array(strtolower($mime), ['image/jpeg', 'image/jpg'], true);

        if (extension_loaded('imagick')) {
            // Хинт jpeg:size заставляет libjpeg декодировать сразу в уменьшенном
            // DCT-масштабе — оцениваем память по уменьшенным габаритам, а не по
            // полным. Ставим его ТОЛЬКО когда исходник заведомо больше хинта:
            // для маленького JPEG libjpeg по нему масштабирует ВВЕРХ (640×480 с
            // хинтом 3200 декодировался в 1280×960 — поймано CI-тестом
            // small_image_keeps_its_dimensions), а при неизвестных габаритах
            // рисковать апскейлом нельзя.
            $useJpegHint = $isJpeg && $width > 0 && max($width, $height) > 2 * $maxEdge;

            [$estW, $estH] = $useJpegHint
                ? $this->jpegHintedDimensions($width, $height, $maxEdge)
                : [$width, $height];

            if ($width === 0 || RasterImageMemory::fits($estW, $estH, self::BPP_IMAGICK)) {
                $jpeg = $this->convertWithImagick($bytes, $maxEdge, $quality, $useJpegHint);
                if ($jpeg !== null) {
                    return $jpeg;
                }
            }
        }

        if (function_exists('imagecreatefromstring')) {
            if ($width === 0 || RasterImageMemory::fits($width, $height, self::BPP_GD)) {
                $jpeg = $this->convertWithGd($bytes, $mime, $maxEdge, $quality);
                if ($jpeg !== null) {
                    return $jpeg;
                }
            } else {
                return self::PAGE_TOO_LARGE;
            }
        }

        // Сюда попадает и «оба движка отказались», и «GD нет, Imagick не влез».
        return $width > 0 ? self::PAGE_TOO_LARGE : null;
    }

    /**
     * Габариты без декодирования: getimagesizefromstring читает заголовок;
     * heic/heif он не знает — тогда Imagick::pingImageBlob (тоже только шапка).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function probeDimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info !== false && (int) $info[0] > 0 && (int) $info[1] > 0) {
            return [(int) $info[0], (int) $info[1]];
        }

        if (extension_loaded('imagick')) {
            try {
                $probe = new \Imagick;
                $probe->pingImageBlob($bytes);
                $w = $probe->getImageWidth();
                $h = $probe->getImageHeight();
                $probe->clear();
                if ($w > 0 && $h > 0) {
                    return [$w, $h];
                }
            } catch (\Throwable) {
                // не распознали — вернём null ниже
            }
        }

        return [null, null];
    }

    /**
     * До каких габаритов libjpeg ужмёт декод при хинте jpeg:size = 2×maxEdge.
     * Консервативная модель DCT-масштабов: делим пополам, пока длинная сторона
     * не станет меньше двух хинтов.
     *
     * @return array{0: int, 1: int}
     */
    private function jpegHintedDimensions(int $width, int $height, int $maxEdge): array
    {
        $hint = 2 * $maxEdge;
        while (max($width, $height) >= 2 * $hint && $width > 1 && $height > 1) {
            $width = intdiv($width, 2);
            $height = intdiv($height, 2);
        }

        return [$width, $height];
    }

    private function convertWithImagick(string $bytes, int $maxEdge, int $quality, bool $useJpegHint): ?string
    {
        try {
            $im = new \Imagick;

            // Потолки ImageMagick: при превышении он уходит в дисковый
            // pixel-cache вместо фатального OOM всего процесса.
            $im->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 64 * 1024 * 1024);
            $im->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 128 * 1024 * 1024);

            // Только для JPEG, заведомо большего хинта: на маленьком libjpeg
            // масштабирует по хинту ВВЕРХ (см. convertToPdfJpeg).
            if ($useJpegHint) {
                $im->setOption('jpeg:size', (2 * $maxEdge).'x'.(2 * $maxEdge));
            }

            $im->readImageBlob($bytes);
            $im->setIteratorIndex(0); // GIF и прочие многокадровые — первый кадр

            // dompdf игнорирует EXIF Orientation, а перекодирование стирает
            // тег — без поворота лежачие телефонные фото замёрзли бы навсегда.
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }

            if (max($im->getImageWidth(), $im->getImageHeight()) > $maxEdge) {
                $im->thumbnailImage($maxEdge, $maxEdge, true);
            }

            // Прозрачность → белый (как в CertificateService), не чёрный.
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($quality);
            $jpeg = $im->getImageBlob();
            $im->clear();

            return $jpeg !== '' ? $jpeg : null;
        } catch (\Throwable) {
            return null; // упадём в GD-ветку
        }
    }

    private function convertWithGd(string $bytes, string $mime, int $maxEdge, int $quality): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        try {
            if (! imageistruecolor($src)) {
                imagepalettetotruecolor($src);
            }

            // EXIF-поворот для jpeg (3/6/8; зеркальные 2/4/5/7 — экзотика,
            // сознательно не обрабатываем). Без ext-exif молча пропускаем.
            if (in_array(strtolower($mime), ['image/jpeg', 'image/jpg'], true) && function_exists('exif_read_data')) {
                $rotated = $this->gdApplyExifOrientation($src, $bytes);
                if ($rotated !== null) {
                    imagedestroy($src);
                    $src = $rotated;
                }
            }

            $w = imagesx($src);
            $h = imagesy($src);
            $scale = max($w, $h) > $maxEdge ? $maxEdge / max($w, $h) : 1.0;
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));

            $canvas = imagecreatetruecolor($tw, $th);
            // Белая подложка: альфа источника сблендится на белый, не на чёрный.
            imagefilledrectangle($canvas, 0, 0, $tw - 1, $th - 1, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

            ob_start();
            imagejpeg($canvas, null, $quality);
            $jpeg = (string) ob_get_clean();
            imagedestroy($canvas);

            return $jpeg !== '' ? $jpeg : null;
        } finally {
            if ($src instanceof \GdImage) {
                imagedestroy($src);
            }
        }
    }

    /** Повёрнутая копия по EXIF Orientation, либо null если поворот не нужен. */
    private function gdApplyExifOrientation(\GdImage $src, string $bytes): ?\GdImage
    {
        try {
            $stream = fopen('php://temp', 'r+b');
            if ($stream === false) {
                return null;
            }
            fwrite($stream, $bytes);
            rewind($stream);
            $exif = @exif_read_data($stream);
            fclose($stream);
        } catch (\Throwable) {
            return null;
        }

        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return null;
        }

        $rotated = imagerotate($src, $angle, 0);

        return $rotated === false ? null : $rotated;
    }
}
