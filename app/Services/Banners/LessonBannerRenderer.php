<?php

declare(strict_types=1);

namespace App\Services\Banners;

use App\Models\LessonBannerTemplate;
use App\Support\RasterImageMemory;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Рисует плашку по шаблону. Только GD + FreeType — на проде оба есть
 * (замер 22-09-2026), Photoshop и Imagick не нужны.
 *
 * Порядок слоёв повторяет PSD:
 *   1. фон (background) — всё, что лежит в PSD ПОД полями;
 *   2. поля с "layer":"under" — например, огромный водяной номер Кочергиной,
 *      который в PSD стоит над плашками, но под фото преподавателя;
 *   3. прозрачный верхний слой (overlay), если он есть, — всё, что в PSD лежит
 *      над under-полями (фото, логотип, надписи);
 *   4. остальные поля (дата, номер) — поверх всего.
 *
 * Виды поля:
 *   • обычный текст;
 *   • "badge": {"shape":"circle","diameter":N} — закрашенный круг цвета поля,
 *     цифры ВЫРЕЗАНЫ насквозь (сквозь них видно, что под кругом). Так в макетах
 *     рисует номер OpenType-функция Fedra Sans Pro: «(14)» → ⓮. GD OpenType-функций
 *     не применяет, поэтому круг рисуется здесь, а в текст идут только цифры;
 *   • "blend":"soft_light" + "opacity" — наложение «Мягкий свет» с заливкой,
 *     как у водяного номера (в PSD: Soft Light, заливка 59%).
 *
 * Кегль в spec — в ПИКСЕЛЯХ фона (при 72 ppi пункт PSD = пиксель). FreeType в GD
 * считает в пунктах при 96 dpi, отсюда PX_TO_GD_PT.
 */
final class LessonBannerRenderer
{
    private const PX_TO_GD_PT = 0.75;

    /** Ниже этой доли исходного кегля fit не ужимает — лучше вылезти, чем стать нечитаемым. */
    private const MIN_FIT_RATIO = 0.4;

    /** truecolor GD ≈ 5 байт на пиксель с запасом (см. RasterImageMemory). */
    private const GD_BYTES_PER_PIXEL = 5.0;

    /** Сверхвыборка круга значка: GD рисует круги без сглаживания. */
    private const BADGE_SUPERSAMPLE = 4;

    /** Цифры в значке занимают не больше этой доли диаметра по ширине. */
    private const BADGE_TEXT_MAX_WIDTH = 0.78;

    /**
     * @param  array<string, string>  $texts  имя поля → готовый текст
     * @return string JPEG-байты
     */
    public function render(LessonBannerTemplate $template, array $texts): string
    {
        // Фон + верхний слой + маска водяного знака — три полотна разом.
        if (! RasterImageMemory::fits($template->width, $template->height, self::GD_BYTES_PER_PIXEL * 3)) {
            throw new RuntimeException("Фон шаблона #{$template->id} ({$template->width}×{$template->height}) не влезает в memory_limit.");
        }

        $image = $this->load($template->background_disk, $template->background_path, "Фон шаблона #{$template->id}");

        try {
            // JPEG без альфы: полупрозрачный фон PNG ложится на белое, а не на чёрное.
            $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
            imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

            $this->drawFields($canvas, $template, $texts, under: true);

            if (filled($template->overlay_path) && filled($template->overlay_disk)) {
                $overlay = $this->load((string) $template->overlay_disk, (string) $template->overlay_path, "Верхний слой шаблона #{$template->id}");
                imagealphablending($canvas, true);
                imagecopy($canvas, $overlay, 0, 0, 0, 0, imagesx($overlay), imagesy($overlay));
                imagedestroy($overlay);
            }

            $this->drawFields($canvas, $template, $texts, under: false);

            ob_start();
            imagejpeg($canvas, null, max(0, min(100, (int) config('lesson_banners.jpeg_quality', 90))));

            return (string) ob_get_clean();
        } finally {
            imagedestroy($image);
            if (isset($canvas)) {
                imagedestroy($canvas);
            }
        }
    }

    /** @param  array<string, string>  $texts */
    private function drawFields(GdImage $canvas, LessonBannerTemplate $template, array $texts, bool $under): void
    {
        foreach ($texts as $name => $text) {
            $field = $template->field($name);
            if ($field === [] || $text === '' || ((string) ($field['layer'] ?? 'over') === 'under') !== $under) {
                continue;
            }

            if ((bool) ($field['uppercase'] ?? false)) {
                $text = mb_strtoupper($text);
            }

            $font = $this->fontPath((string) ($field['font'] ?? ''), $template->id);

            if (is_array($field['badge'] ?? null)) {
                $this->drawBadge($canvas, $field, $text, $font);
            } elseif ((string) ($field['blend'] ?? 'normal') !== 'normal' || (float) ($field['opacity'] ?? 1) < 1.0) {
                $this->drawBlended($canvas, $field, $text, $font);
            } else {
                $this->drawText($canvas, $field, $text, $font, $this->color($canvas, (string) ($field['color'] ?? '#000000')));
            }
        }
    }

    /**
     * Геометрия строки в рамке поля: кегль (после fit), левый край и базовая линия.
     *
     * @param  array<string, mixed>  $field
     * @return array{size: float, x: float, baseline: float, tracking: float, width: float, top: float, bottom: float}
     */
    private function layout(array $field, string $text, string $font, int $canvasWidth): array
    {
        $boxX = (float) ($field['x'] ?? 0);
        $boxY = (float) ($field['y'] ?? 0);
        $boxW = max(1.0, (float) ($field['w'] ?? $canvasWidth));
        $boxH = max(0.0, (float) ($field['h'] ?? 0));
        $tracking = (float) ($field['tracking'] ?? 0);

        $size = max(1.0, (float) ($field['size_px'] ?? 32)) * self::PX_TO_GD_PT;
        [$width, $top, $bottom] = $this->measure($text, $font, $size, $tracking);

        if ((bool) ($field['fit'] ?? true) && $width > $boxW) {
            $size = max($size * self::MIN_FIT_RATIO, $size * $boxW / $width);
            [$width, $top, $bottom] = $this->measure($text, $font, $size, $tracking);
        }

        $x = match ((string) ($field['align'] ?? 'left')) {
            'center' => $boxX + ($boxW - $width) / 2,
            'right' => $boxX + $boxW - $width,
            default => $boxX,
        };

        // Вертикально — центр прямоугольника по реальной высоте глифов.
        // Без h (0) — y считается верхом строки.
        $baseline = $boxH > 0
            ? $boxY + ($boxH - ($bottom - $top)) / 2 - $top
            : $boxY - $top;

        return compact('size', 'x', 'baseline', 'tracking', 'width', 'top', 'bottom');
    }

    /** @param  array<string, mixed>  $field */
    private function drawText(GdImage $canvas, array $field, string $text, string $font, int $color): void
    {
        $l = $this->layout($field, $text, $font, imagesx($canvas));
        $this->ttf($canvas, $l['size'], $l['x'], $l['baseline'], $color, $font, $text, $l['tracking']);
    }

    /**
     * Круг цвета поля, цифры вырезаны насквозь. Маска рисуется в BADGE_SUPERSAMPLE
     * раз крупнее и ужимается — так у круга и у вырезанных цифр гладкие края.
     *
     * @param  array<string, mixed>  $field
     */
    private function drawBadge(GdImage $canvas, array $field, string $text, string $font): void
    {
        $diameter = max(4, (int) round((float) ($field['badge']['diameter'] ?? min((float) ($field['w'] ?? 0), (float) ($field['h'] ?? 0)))));
        $cx = (float) ($field['x'] ?? 0) + (float) ($field['w'] ?? $diameter) / 2;
        $cy = (float) ($field['y'] ?? 0) + (float) ($field['h'] ?? $diameter) / 2;

        $s = self::BADGE_SUPERSAMPLE;
        $big = $diameter * $s;
        $mask = imagecreatetruecolor($big, $big);
        imagefill($mask, 0, 0, (int) imagecolorallocate($mask, 0, 0, 0));
        imagefilledellipse($mask, intdiv($big, 2), intdiv($big, 2), $big, $big, (int) imagecolorallocate($mask, 255, 255, 255));

        // Цифры — чёрным по белому кругу: это «дырки» в маске.
        $size = max(1.0, (float) ($field['size_px'] ?? $diameter * 0.62)) * self::PX_TO_GD_PT * $s;
        $tracking = (float) ($field['tracking'] ?? 0) * $s;
        [$width, $top, $bottom] = $this->measure($text, $font, $size, $tracking);
        $maxWidth = $big * self::BADGE_TEXT_MAX_WIDTH;
        if ($width > $maxWidth) {
            $size *= $maxWidth / $width;
            [$width, $top, $bottom] = $this->measure($text, $font, $size, $tracking);
        }
        $x = ($big - $width) / 2;
        $baseline = ($big - ($bottom - $top)) / 2 - $top;
        $this->ttf($mask, $size, $x, $baseline, (int) imagecolorallocate($mask, 0, 0, 0), $font, $text, $tracking);

        $small = imagecreatetruecolor($diameter, $diameter);
        imagecopyresampled($small, $mask, 0, 0, 0, 0, $diameter, $diameter, $big, $big);
        imagedestroy($mask);

        [$r, $g, $b] = $this->rgb((string) ($field['color'] ?? '#000000'));
        $left = (int) round($cx - $diameter / 2);
        $topY = (int) round($cy - $diameter / 2);
        $cw = imagesx($canvas);
        $ch = imagesy($canvas);

        for ($py = 0; $py < $diameter; $py++) {
            for ($px = 0; $px < $diameter; $px++) {
                $coverage = ((imagecolorat($small, $px, $py) >> 16) & 0xFF) / 255;
                $tx = $left + $px;
                $ty = $topY + $py;
                if ($coverage <= 0 || $tx < 0 || $ty < 0 || $tx >= $cw || $ty >= $ch) {
                    continue;
                }
                $under = imagecolorat($canvas, $tx, $ty);
                imagesetpixel($canvas, $tx, $ty, $this->mix($canvas, $under, [$r, $g, $b], $coverage));
            }
        }
        imagedestroy($small);
    }

    /**
     * Текст с наложением («Мягкий свет») и/или прозрачностью. Глифы рисуются
     * в отдельную маску покрытия, потом по пикселям смешиваются с полотном.
     *
     * @param  array<string, mixed>  $field
     */
    private function drawBlended(GdImage $canvas, array $field, string $text, string $font): void
    {
        $cw = imagesx($canvas);
        $ch = imagesy($canvas);
        $l = $this->layout($field, $text, $font, $cw);

        $mask = imagecreatetruecolor($cw, $ch);
        imagefill($mask, 0, 0, (int) imagecolorallocate($mask, 0, 0, 0));
        $this->ttf($mask, $l['size'], $l['x'], $l['baseline'], (int) imagecolorallocate($mask, 255, 255, 255), $font, $text, $l['tracking']);

        $x0 = max(0, (int) floor($l['x']) - 4);
        $x1 = min($cw - 1, (int) ceil($l['x'] + $l['width']) + 4);
        $y0 = max(0, (int) floor($l['baseline'] + $l['top']) - 4);
        $y1 = min($ch - 1, (int) ceil($l['baseline'] + $l['bottom']) + 4);

        $source = $this->rgb((string) ($field['color'] ?? '#000000'));
        $opacity = max(0.0, min(1.0, (float) ($field['opacity'] ?? 1)));
        $blend = (string) ($field['blend'] ?? 'normal');

        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $coverage = ((imagecolorat($mask, $x, $y) >> 16) & 0xFF) / 255;
                if ($coverage <= 0) {
                    continue;
                }
                $under = imagecolorat($canvas, $x, $y);
                $target = $blend === 'soft_light'
                    ? $this->softLight($under, $source)
                    : $source;
                imagesetpixel($canvas, $x, $y, $this->mix($canvas, $under, $target, $coverage * $opacity));
            }
        }
        imagedestroy($mask);
    }

    /**
     * «Мягкий свет» по W3C Compositing (так же считает Photoshop с точностью до
     * округления): результат для основы b и источника s, покомпонентно.
     *
     * @param  array{0: int, 1: int, 2: int}  $source
     * @return array{0: int, 1: int, 2: int}
     */
    private function softLight(int $under, array $source): array
    {
        $out = [];
        foreach ([16, 8, 0] as $i => $shift) {
            $b = (($under >> $shift) & 0xFF) / 255;
            $s = $source[$i] / 255;
            if ($s <= 0.5) {
                $r = $b - (1 - 2 * $s) * $b * (1 - $b);
            } else {
                $d = $b <= 0.25 ? ((16 * $b - 12) * $b + 4) * $b : sqrt($b);
                $r = $b + (2 * $s - 1) * ($d - $b);
            }
            $out[] = (int) round(max(0, min(1, $r)) * 255);
        }

        return $out;
    }

    /** @param  array{0: int, 1: int, 2: int}  $target */
    private function mix(GdImage $canvas, int $under, array $target, float $alpha): int
    {
        $alpha = max(0.0, min(1.0, $alpha));
        $channels = [];
        foreach ([16, 8, 0] as $i => $shift) {
            $base = ($under >> $shift) & 0xFF;
            $channels[] = (int) round($base + ($target[$i] - $base) * $alpha);
        }

        return (int) imagecolorallocate($canvas, $channels[0], $channels[1], $channels[2]);
    }

    private function ttf(GdImage $image, float $size, float $x, float $baseline, int $color, string $font, string $text, float $tracking): void
    {
        if ($tracking == 0.0) {
            imagettftext($image, $size, 0, (int) round($x), (int) round($baseline), $color, $font, $text);

            return;
        }

        $cursor = $x;
        foreach (mb_str_split($text) as $char) {
            imagettftext($image, $size, 0, (int) round($cursor), (int) round($baseline), $color, $font, $char);
            $cursor += $this->charAdvance($char, $font, $size) + $tracking;
        }
    }

    /**
     * Ширина строки и вертикальные границы глифов относительно базовой линии.
     *
     * @return array{0: float, 1: float, 2: float} [width, top (<0), bottom]
     */
    private function measure(string $text, string $font, float $size, float $tracking): array
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            throw new RuntimeException("FreeType не измерил текст шрифтом {$font}.");
        }

        $top = (float) min($box[5], $box[7]);
        $bottom = (float) max($box[1], $box[3]);

        if ($tracking == 0.0) {
            return [(float) (max($box[2], $box[4]) - min($box[0], $box[6])), $top, $bottom];
        }

        $width = 0.0;
        $chars = mb_str_split($text);
        foreach ($chars as $char) {
            $width += $this->charAdvance($char, $font, $size);
        }

        return [$width + $tracking * max(0, count($chars) - 1), $top, $bottom];
    }

    private function charAdvance(string $char, string $font, float $size): float
    {
        $box = imagettfbbox($size, 0, $font, $char);

        return $box === false ? 0.0 : (float) (max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    private function load(string $disk, string $path, string $what): GdImage
    {
        $bytes = Storage::disk($disk)->get($path);
        if ($bytes === null || $bytes === '') {
            throw new RuntimeException("{$what} не найден: {$disk}:{$path}.");
        }

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new RuntimeException("{$what} не читается как картинка.");
        }

        return $image;
    }

    private function fontPath(string $name, int $templateId): string
    {
        if ($name !== '') {
            foreach ([$name, rtrim((string) config('lesson_banners.fonts_dir'), '/\\').DIRECTORY_SEPARATOR.$name] as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        $fallback = (string) config('lesson_banners.fallback_font');
        Log::warning('Плашка занятия: шрифт не найден — рисую запасным.', [
            'template_id' => $templateId,
            'font' => $name,
            'fallback' => $fallback,
        ]);

        if (! is_file($fallback)) {
            throw new RuntimeException("Нет ни шрифта «{$name}», ни запасного {$fallback}.");
        }

        return $fallback;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '000000';
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private function color(GdImage $canvas, string $hex): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return (int) imagecolorallocate($canvas, $r, $g, $b);
    }
}
