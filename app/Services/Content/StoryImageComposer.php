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
        $small = imagecreatetruecolor(36, 64);
        imagecopyresampled($small, $src, 0, 0, intdiv($w - $cw, 2), intdiv($h - $ch, 2), 36, 64, $cw, $ch);
        for ($i = 0; $i < 3; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagecopyresampled($canvas, $small, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, 36, 64);
        imagefilter($canvas, IMG_FILTER_GAUSSIAN_BLUR);
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
