<?php

declare(strict_types=1);

namespace App\Services\Media;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Перекодирование растровой картинки на диске в WebP.
 *
 * Зачем: замер витрины 18-08-2026 (H3082) показал 89 обложек курсов на 27,6 МБ —
 * фотографии, сохранённые в PNG. Образец 533×399 px весил 488 КБ; тот же кадр в
 * WebP q82 — около 40 КБ. Пиксельный размер при этом правильный, лишний вес даёт
 * ровно формат, поэтому ресайза здесь нет и не должно быть: класс меняет КОДЕК,
 * а не кадр. Кто захочет ресайз — пусть заводит отдельный сервис, иначе обложка
 * начнёт незаметно терять детали при каждом прогоне.
 *
 * Почему GD, а не новая зависимость: `imagewebp` есть на проде (проверено
 * 18-08-2026: GD с WebP Support + Imagick), а composer-пакет пришлось бы ставить
 * на боевой машине ради двадцати строк кода.
 *
 * ИНВАРИАНТ: исходный файл НЕ удаляется. Удалять его можно только после того,
 * как новый путь доехал до БД, — иначе упавшая транзакция оставит курс с
 * обложкой, которой уже нет на диске. Удаление — забота вызывающего.
 */
class WebpTranscoder
{
    /** IMAGETYPE_* => декодер GD. Список сознательно совпадает с CoverToBannerImporter::CODECS. */
    private const DECODERS = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_GIF => 'imagecreatefromgif',
    ];

    /**
     * Перекодировать файл на диске в WebP рядом с оригиналом.
     *
     * Исключений не бросает: массовый прогон обязан дойти до конца по всем
     * курсам, а не оборваться на первой битой обложке (тот же приём, что в
     * CoverToBannerImporter).
     */
    public function transcode(string $path, ?string $disk = null): WebpTranscodeResult
    {
        $disk ??= (string) config('media.webp.disk', 'public');
        $fs = Storage::disk($disk);

        if (! $this->supported()) {
            return WebpTranscodeResult::skipped($path, 'gd-no-webp');
        }

        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'webp') {
            return WebpTranscodeResult::skipped($path, 'already-webp');
        }

        if (! $fs->exists($path)) {
            return WebpTranscodeResult::skipped($path, 'missing');
        }

        $bytes = $fs->get($path);
        if ($bytes === null || $bytes === '') {
            return WebpTranscodeResult::skipped($path, 'empty');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return WebpTranscodeResult::skipped($path, 'unreadable');
        }

        $decoder = self::DECODERS[(int) $info[2]] ?? null;
        if ($decoder === null) {
            return WebpTranscodeResult::skipped($path, 'unsupported-type');
        }

        $image = null;

        try {
            $image = @imagecreatefromstring($bytes);
            if (! $image instanceof GdImage) {
                return WebpTranscodeResult::skipped($path, 'decode-failed');
            }

            // Без этих двух строк PNG с прозрачностью выходит с чёрным фоном.
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);

            $encoded = $this->encode($image, (int) config('media.webp.quality', 82));
            if ($encoded === null) {
                return WebpTranscodeResult::skipped($path, 'encode-failed');
            }
        } catch (\Throwable $e) {
            Log::warning('webp: transcode failed', ['path' => $path, 'error' => $e->getMessage()]);

            return WebpTranscodeResult::skipped($path, 'exception');
        } finally {
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
        }

        $before = strlen($bytes);
        $after = strlen($encoded);

        // Плоская графика (схемы, скриншоты с крупной заливкой) в PNG нередко
        // ЛЕГЧЕ, чем в WebP. Менять формат ради проигрыша по весу бессмысленно,
        // а обратной дороги у перезаписанной обложки нет.
        $minGain = (float) config('media.webp.min_gain', 0.10);
        if ($after >= $before * (1.0 - $minGain)) {
            return WebpTranscodeResult::skipped($path, 'no-gain', $before, $after);
        }

        $target = $this->targetPath($fs, $path);
        $fs->put($target, $encoded);

        return WebpTranscodeResult::converted($path, $target, $before, $after);
    }

    /** Есть ли вообще чем кодировать. Публично — команда рапортует это до прогона. */
    public function supported(): bool
    {
        return function_exists('imagewebp') && (bool) (gd_info()['WebP Support'] ?? false);
    }

    /**
     * `courses/01ABC.png` -> `courses/01ABC.webp`, а при занятом имени —
     * `courses/01ABC-webp.webp`. Занято оно бывает: у обложек ULID-имена, но
     * рядом уже может лежать одноимённый .webp от прошлой ручной заливки, и
     * молча затереть чужой файл нельзя.
     */
    private function targetPath(Filesystem $fs, string $path): string
    {
        $dir = trim((string) pathinfo($path, PATHINFO_DIRNAME), '.');
        $stem = (string) pathinfo($path, PATHINFO_FILENAME);
        $base = ($dir === '' ? '' : $dir.'/').$stem;

        if (! $fs->exists($base.'.webp')) {
            return $base.'.webp';
        }

        for ($i = 2; $i < 100; $i++) {
            $candidate = $base.'-'.$i.'.webp';
            if (! $fs->exists($candidate)) {
                return $candidate;
            }
        }

        return $base.'-'.uniqid().'.webp';
    }

    private function encode(GdImage $image, int $quality): ?string
    {
        ob_start();
        $ok = imagewebp($image, null, max(1, min(100, $quality)));
        $out = (string) ob_get_clean();

        return ($ok && $out !== '') ? $out : null;
    }
}
