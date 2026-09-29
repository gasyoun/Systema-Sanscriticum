<?php

declare(strict_types=1);

namespace App\Services\Anons;

use RuntimeException;

/**
 * H5049 R5: приёмочный контроль НАД ПИКСЕЛЯМИ экспортированного файла.
 * Если mediaAreaUrl существует, а слой плашки в изображении отсутствует —
 * проверка ПАДАЕТ (регрессия H5049: ссылка без видимой плашки).
 *
 * Проверки: формат/размерность/байт, доля «фоновых» пикселей внутри
 * прямоугольника плашки, отсутствие пересечения с безопасными зонами,
 * переполнение текста (по высоте блока — фиксируется при рендере).
 */
final class PlaqueInspector
{
    /** Доля пикселей цвета плашки внутри прямоугольника, ниже которой fail. */
    public const MIN_BACKGROUND_COVERAGE = 0.35;

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $rectPx  измеренный прямоугольник (PlaqueBounds)
     * @param  array{format?: string, max_bytes?: int, min_w?: int, min_h?: int}  $expectations
     * @return list<string> список проблем; пусто — артефакт принят
     */
    public function inspect(string $artifactPath, array $rectPx, array $expectations = []): array
    {
        $problems = [];

        if (! is_file($artifactPath) || ! is_readable($artifactPath)) {
            return ["artifact missing/unreadable: {$artifactPath}"];
        }

        $bytes = (int) filesize($artifactPath);
        $maxBytes = $expectations['max_bytes'] ?? 8 * 1024 * 1024;
        if ($bytes === 0 || $bytes > $maxBytes) {
            $problems[] = "artifact byte size {$bytes} outside 1..{$maxBytes}.";
        }

        $info = @getimagesize($artifactPath);
        if ($info === false) {
            return ["artifact is not a decodable image: {$artifactPath}"];
        }

        [$w, $h, $type] = $info;
        if (in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) === false) {
            $problems[] = "artifact format is not jpeg/png/webp (type={$type}).";
        }

        $minW = $expectations['min_w'] ?? 320;
        $minH = $expectations['min_h'] ?? 480;
        if ($w < $minW || $h < $minH) {
            $problems[] = "artifact {$w}x{$h} below minimum {$minW}x{$minH}.";
        }

        // Прямоугольник должен существовать и не задевать безопасные зоны.
        $overTop = $rectPx['y'] < (int) (CtaPlaqueCompositor::SAFE_TOP / 100.0 * $h);
        $overBottom = ($rectPx['y'] + $rectPx['h']) > ($h - (int) (CtaPlaqueCompositor::SAFE_BOTTOM / 100.0 * $h));
        if ($overTop || $overBottom) {
            $problems[] = sprintf('plaque rect %s collides with safe zones (top %.0f%% / bottom %.0f%%).',
                json_encode($rectPx), CtaPlaqueCompositor::SAFE_TOP, CtaPlaqueCompositor::SAFE_BOTTOM);
        }

        if ($rectPx['x'] < 0 || $rectPx['y'] < 0
            || $w < $rectPx['x'] + $rectPx['w'] || $h < $rectPx['y'] + $rectPx['h']) {
            $problems[] = 'plaque rect exceeds canvas bounds.';
        }

        // Главный тест: слой плашки физически присутствует в пикселях.
        $img = @imagecreatefromstring((string) file_get_contents($artifactPath));
        if ($img === false) {
            $problems[] = 'artifact bytes do not decode in GD.';

            return $problems;
        }

        $coverage = $this->darkCoverage($img, $rectPx);
        imagedestroy($img);

        if ($coverage < self::MIN_BACKGROUND_COVERAGE) {
            $problems[] = sprintf(
                'plaque layer ABSENT: dark-background coverage inside rect is %.2f (< %.2f). '
                .'A mediaAreaUrl without a rendered plaque is the exact H5049 defect.',
                $coverage,
                self::MIN_BACKGROUND_COVERAGE
            );
        }

        return $problems;
    }

    /** Доля пикселей внутри прямоугольника, достаточно тёмных для фона плашки. */
    /** @param array{x: int, y: int, w: int, h: int} $rect */
    private function darkCoverage(\GdImage $img, array $rect): float
    {
        $total = 0;
        $dark = 0;
        // Внутренняя область (без рамки) — осмысленная доля плашки.
        $inset = max(2, (int) ($rect['h'] * 0.2));
        $x0 = $rect['x'] + $inset;
        $y0 = $rect['y'] + $inset;
        $x1 = $rect['x'] + $rect['w'] - $inset;
        $y1 = $rect['y'] + $rect['h'] - $inset;
        if ($x1 <= $x0 || $y1 <= $y0) {
            return 0.0;
        }

        for ($y = $y0; $y < $y1; $y += 2) {
            for ($x = $x0; $x < $x1; $x += 2) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $total++;
                // Яркость < 96 → фон плашки (или тёмный текст). Оба слоя —
                // признак отрисовки; чистое исходное фото даст ~0.
                if (0.2126 * $r + 0.7152 * $g + 0.0722 * $b < 96) {
                    $dark++;
                }
            }
        }

        if ($total === 0) {
            throw new RuntimeException('Plaque rect has zero sampled area — inspect coordinates are wrong.');
        }

        return $dark / $total;
    }
}
