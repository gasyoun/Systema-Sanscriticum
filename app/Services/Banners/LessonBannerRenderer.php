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
 * Рисует плашку: фон шаблона + тексты полей по spec. Только GD + FreeType —
 * на проде оба есть (замер 22-09-2026), Photoshop и Imagick не нужны.
 *
 * Кегль в spec хранится в ПИКСЕЛЯХ фона (так его отдаёт разбор PSD: при 72 ppi
 * пункт PSD = пиксель). FreeType в GD считает кегль в пунктах при 96 dpi,
 * отсюда пересчёт PX_TO_GD_PT.
 */
final class LessonBannerRenderer
{
    private const PX_TO_GD_PT = 0.75;

    /** Ниже этой доли исходного кегля fit не ужимает — лучше вылезти, чем стать нечитаемым. */
    private const MIN_FIT_RATIO = 0.4;

    /** truecolor GD ≈ 5 байт на пиксель с запасом (см. RasterImageMemory). */
    private const GD_BYTES_PER_PIXEL = 5.0;

    /**
     * @param  array<string, string>  $texts  имя поля → готовый текст
     * @return string JPEG-байты
     */
    public function render(LessonBannerTemplate $template, array $texts): string
    {
        if (! RasterImageMemory::fits($template->width, $template->height, self::GD_BYTES_PER_PIXEL)) {
            throw new RuntimeException("Фон шаблона #{$template->id} ({$template->width}×{$template->height}) не влезает в memory_limit.");
        }

        $bytes = Storage::disk($template->background_disk)->get($template->background_path);
        if ($bytes === null || $bytes === '') {
            throw new RuntimeException("Фон шаблона #{$template->id} не найден: {$template->background_disk}:{$template->background_path}.");
        }

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new RuntimeException("Фон шаблона #{$template->id} не читается как картинка.");
        }

        try {
            // JPEG без альфы: полупрозрачный фон PNG ложится на белое, а не на чёрное.
            $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

            foreach ($texts as $name => $text) {
                $field = $template->field($name);
                if ($field === [] || $text === '') {
                    continue;
                }
                $this->drawField($canvas, $field, $text, $template->id);
            }

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

    /** @param  array<string, mixed>  $field */
    private function drawField(GdImage $canvas, array $field, string $text, int $templateId): void
    {
        if ((bool) ($field['uppercase'] ?? false)) {
            $text = mb_strtoupper($text);
        }

        $font = $this->fontPath((string) ($field['font'] ?? ''), $templateId);
        $boxX = (int) ($field['x'] ?? 0);
        $boxY = (int) ($field['y'] ?? 0);
        $boxW = max(1, (int) ($field['w'] ?? imagesx($canvas)));
        $boxH = max(1, (int) ($field['h'] ?? 0));
        $tracking = (float) ($field['tracking'] ?? 0);

        $size = max(1.0, (float) ($field['size_px'] ?? 32)) * self::PX_TO_GD_PT;
        [$width, $top, $bottom] = $this->measure($text, $font, $size, $tracking);

        if ((bool) ($field['fit'] ?? true) && $width > $boxW) {
            $fitted = max($size * self::MIN_FIT_RATIO, $size * $boxW / $width);
            $size = $fitted;
            [$width, $top, $bottom] = $this->measure($text, $font, $size, $tracking);
        }

        $x = match ((string) ($field['align'] ?? 'left')) {
            'center' => $boxX + ($boxW - $width) / 2,
            'right' => $boxX + $boxW - $width,
            default => $boxX,
        };

        // Вертикально — центр прямоугольника по реальной высоте глифов.
        // Без h (0) — y считается верхом строки.
        $textHeight = $bottom - $top;
        $baseline = $boxH > 0
            ? $boxY + ($boxH - $textHeight) / 2 - $top
            : $boxY - $top;

        $color = $this->color($canvas, (string) ($field['color'] ?? '#000000'));

        if ($tracking == 0.0) {
            imagettftext($canvas, $size, 0, (int) round($x), (int) round($baseline), $color, $font, $text);

            return;
        }

        $cursor = $x;
        foreach (mb_str_split($text) as $char) {
            imagettftext($canvas, $size, 0, (int) round($cursor), (int) round($baseline), $color, $font, $char);
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

    private function color(GdImage $canvas, string $hex): int
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '000000';
        }

        return (int) imagecolorallocate($canvas, (int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
    }
}
