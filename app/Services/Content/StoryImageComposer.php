<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Картинка для сторис из картинки поста (28-10-2026): обложка целиком по
 * центру кадра 1080×1920 на размытой и затемнённой копии себя же. Так
 * рубрика с галочкой «Сторис» не требует отдельной вертикальной картинки.
 */
class StoryImageComposer
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    /** @return string путь новой картинки на диске слотов */
    public function compose(string $mediaPath): string
    {
        $disk = Storage::disk(ContentCalendarSlot::MEDIA_DISK);
        $src = @imagecreatefromstring((string) $disk->get($mediaPath));
        if (! $src instanceof GdImage) {
            throw new RuntimeException("сторис: не удалось прочитать картинку {$mediaPath}");
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        // Фон: заполняем кадр (cover), размываем уменьшением и обратным
        // растяжением, затемняем.
        $scale = max(self::WIDTH / $w, self::HEIGHT / $h);
        $cw = min($w, (int) ceil(self::WIDTH / $scale));
        $ch = min($h, (int) ceil(self::HEIGHT / $scale));
        // Растягиваем ступенями с размытием на каждой — без ступенек-пикселей.
        $layer = imagecreatetruecolor(27, 48);
        imagecopyresampled($layer, $src, 0, 0, intdiv($w - $cw, 2), intdiv($h - $ch, 2), 27, 48, $cw, $ch);
        foreach ([[54, 96], [135, 240], [270, 480], [540, 960], [self::WIDTH, self::HEIGHT]] as [$lw, $lh]) {
            for ($i = 0; $i < 4; $i++) {
                imagefilter($layer, IMG_FILTER_GAUSSIAN_BLUR);
            }
            $next = imagecreatetruecolor($lw, $lh);
            imagecopyresampled($next, $layer, 0, 0, 0, 0, $lw, $lh, imagesx($layer), imagesy($layer));
            $layer = $next;
        }
        imagefilter($layer, IMG_FILTER_GAUSSIAN_BLUR);
        imagecopy($canvas, $layer, 0, 0, 0, 0, self::WIDTH, self::HEIGHT);
        imagefilter($canvas, IMG_FILTER_BRIGHTNESS, -70);

        // Обложка целиком (contain) по центру.
        $fit = min(self::WIDTH / $w, self::HEIGHT / $h);
        $fw = (int) round($w * $fit);
        $fh = (int) round($h * $fit);
        imagecopyresampled($canvas, $src, intdiv(self::WIDTH - $fw, 2), intdiv(self::HEIGHT - $fh, 2), 0, 0, $fw, $fh, $w, $h);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = (string) ob_get_clean();

        $path = 'content-calendar/stories/'.Str::ulid().'.jpg';
        $disk->put($path, $jpeg, 'public');

        return $path;
    }
}
