<?php

declare(strict_types=1);

namespace App\Services\Anons;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * H5049 R4: единый шаблон видимой CTA-плашки — ФИЗИЧЕСКИ отрисованной в
 * пиксели изображения ДО загрузки. Координаты mediaAreaUrl — это кликабельная
 * зона; сама по себе она ничего не рисует. Плашка и клик-зона делят ОДИН
 * измеренный прямоугольник (x=50, y=55, w=78, h=14 в процентах холста,
 * radius 7 — форма, живущая в StoryPublisher::send).
 *
 * Типографика: контролируемый padding, контраст (фон тёмный/светлый), перенос
 * строк по ширине, безопасные зоны (сверху/снизу 8% — служебные UI Telegram).
 * Шрифт: TTF через freetype (кириллица); конфиг services.anons.cta_font,
 * env ANONS_CTA_FONT, известные системные пути, ASCII-fallback imagestring.
 */
final class CtaPlaqueCompositor
{
    /** Прямоугольник (проценты холста) — ИСТОЧНИК ИСТИНЫ, тот же у mediaAreaUrl. */
    public const RECT = ['x' => 50.0, 'y' => 55.0, 'w' => 78.0, 'h' => 14.0, 'radius' => 7.0];

    /** Проценты холста, которые клик-зона не должна пересекать. */
    public const SAFE_TOP = 8.0;

    public const SAFE_BOTTOM = 8.0;

    /** Отрисованная плашка: фон, текст, границы прямоугольника в пикселях. */
    public readonly PlaqueBounds $bounds;

    public function __construct(
        private readonly ?string $fontPath = null,
    ) {
        $this->bounds = new PlaqueBounds;
    }

    /**
     * Вжарить плашку в изображение. Возвращает путь к готовому артефакту.
     *
     * @param  array{asset: string, cta_text: string}  $frame
     */
    public function renderPlaque(string $sourcePath, array $frame, string $outPath): string
    {
        $src = $this->load($sourcePath);
        $w = imagesx($src);
        $h = imagesy($src);

        $rect = $this->pixelRect($w, $h);
        $text = trim((string) $frame['cta_text']);
        if ($text === '') {
            throw new RuntimeException('CTA text is empty — a Story without a visible plaque is not publishable.');
        }

        $bg = $this->allocate($src, 17, 17, 17);
        $fg = $this->allocate($src, 255, 255, 255);
        $this->drawRoundedRect($src, $rect, self::RECT['radius'] / 100.0 * min($w, $h), $bg);
        $this->drawText($src, $rect, $text, $fg, $w, $h);

        $this->save($src, $outPath, $sourcePath);
        imagedestroy($src);

        $this->bounds->record($w, $h, $rect, $text, $outPath);

        return $outPath;
    }

    /**
     * Прямоугольник плашки в пикселях — ТОТ ЖЕ, что уходит в mediaAreaUrl.
     *
     * Семантика layout-константы (anons-скилл): x=50 — ЦЕНТР по X («large,
     * centered overlay»), y=55 — верхняя кромка, w=78/h=14 — размеры в
     * процентах холста. left = x − w/2 = 11% → плашка целиком в холсте,
     * клик-зона ложится ровно на отрисованную область.
     */
    /** @return array{x: int, y: int, w: int, h: int} */
    public function pixelRect(int $canvasW, int $canvasH): array
    {
        $w = (int) round(self::RECT['w'] / 100.0 * $canvasW);
        $h = (int) round(self::RECT['h'] / 100.0 * $canvasH);

        return [
            'x' => (int) round(self::RECT['x'] / 100.0 * $canvasW) - (int) ($w / 2),
            'y' => (int) round(self::RECT['y'] / 100.0 * $canvasH),
            'w' => $w,
            'h' => $h,
        ];
    }

    private function load(string $path): \GdImage
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Story asset missing/unreadable: {$path}");
        }

        $info = @getimagesize($path);
        $src = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $src instanceof \GdImage) {
            throw new RuntimeException("Unsupported story image format: {$path} (jpeg/png/webp only).");
        }

        return $src;
    }

    private function allocate(\GdImage $img, int $r, int $g, int $b): int
    {
        return (int) imagecolorallocate($img, $r, $g, $b);
    }

    /** @param array{x: int, y: int, w: int, h: int} $rect */
    private function drawRoundedRect(\GdImage $img, array $rect, float $radius, int $color): void
    {
        $r = (int) min($radius, $rect['h'] / 2);
        imagefilledrectangle($img, $rect['x'], $rect['y'], $rect['x'] + $rect['w'], $rect['y'] + $rect['h'], $color);
        // Скругления четырьмя четвертями круга.
        foreach ([[$rect['x'], $rect['y']], [$rect['x'] + $rect['w'], $rect['y']], [$rect['x'], $rect['y'] + $rect['h']], [$rect['x'] + $rect['w'], $rect['y'] + $rect['h']]] as $i => [$cx, $cy]) {
            $sx = ($i % 2 === 0) ? 180 : 270;
            $ex = ($i < 2) ? $sx + 90 : $sx + 90;
            imagearc($img, $cx, $cy, 2 * $r, 2 * $r, $i < 2 ? $sx : $sx, $i < 2 ? $ex : $ex, $color);
            $px = $i % 2 === 0 ? $cx + 1 : $cx - 1;
            $py = $i < 2 ? $cy + 1 : $cy - 1;
            imagefilledrectangle($img, min($cx, $px), min($cy, $py), max($cx, $px), max($cy, $py), $color);
        }
    }

    /** @param array{x: int, y: int, w: int, h: int} $rect */
    private function drawText(\GdImage $img, array $rect, string $text, int $color, int $w, int $h): void
    {
        $font = $this->resolveFont();
        $padding = max(8, (int) ($rect['h'] * 0.18));

        if ($font !== null) {
            $size = max(9, (int) ($rect['h'] * 0.42));
            $lines = $this->wrapTtf($text, $font, $size, $rect['w'] - 2 * $padding);
            $lineH = (int) ($size * 1.25);
            $blockH = count($lines) * $lineH;
            $y = $rect['y'] + (int) (($rect['h'] - $blockH) / 2) + $size;
            foreach ($lines as $line) {
                $box = imagettfbbox($size, 0, $font, $line);
                $lineW = (int) (abs($box[4] - $box[0]));
                $x = $rect['x'] + (int) (($rect['w'] - $lineW) / 2);
                imagettftext($img, $size, 0, $x, $y, $color, $font, $line);
                $y += $lineH;
            }

            return;
        }

        // ASCII-fallback (без freetype): мелкий встроенный шрифт GD.
        Log::debug('Anons CTA compositor: no TTF font resolved, using GD built-in (ASCII only).');
        $fontSize = 5;
        $charW = imagefontwidth($fontSize);
        $lineH = imagefontheight($fontSize);
        $maxChars = max(4, (int) (($rect['w'] - 2 * $padding) / $charW));
        $lines = $this->wrapAscii($text, $maxChars);
        $blockH = count($lines) * $lineH;
        $y = $rect['y'] + (int) (($rect['h'] - $blockH) / 2);
        foreach ($lines as $line) {
            $lineW = strlen($line) * $charW;
            $x = $rect['x'] + (int) (($rect['w'] - $lineW) / 2);
            imagestring($img, $fontSize, $x, $y, $line, $color);
            $y += $lineH;
        }
    }

    /** @return list<string> */
    private function wrapTtf(string $text, string $font, int $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', $text) ?: [$text];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            $box = imagettfbbox($size, 0, $font, $candidate);
            $w = (int) abs($box[4] - $box[0]);
            if ($w > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;

                continue;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /** @return list<string> */
    private function wrapAscii(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (strlen($candidate) > $maxChars && $current !== '') {
                $lines[] = $current;
                $current = $word;

                continue;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function resolveFont(): ?string
    {
        $candidates = array_values(array_filter([
            config('services.anons.cta_font'),
            env('ANONS_CTA_FONT'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
        ]));

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function save(\GdImage $img, string $outPath, string $sourcePath): void
    {
        $dir = dirname($outPath);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create preview dir: {$dir}");
        }

        $ok = match (strtolower(pathinfo($outPath, PATHINFO_EXTENSION))) {
            'png' => imagepng($img, $outPath),
            default => imagejpeg($img, $outPath, 92),
        };

        if (! $ok) {
            throw new RuntimeException("Failed to write composited CTA image: {$outPath} (from {$sourcePath}).");
        }
    }
}

/**
 * Измеренные границы последней отрисовки —Evidence R5: preview и тест
 * сверяют их с координатами mediaAreaUrl (тот же прямоугольник).
 */
final class PlaqueBounds
{
    public ?int $canvasW = null;

    public ?int $canvasH = null;

    public ?array $rectPx = null;

    public ?string $text = null;

    public ?string $artifactPath = null;

    /** @param array{x: int, y: int, w: int, h: int} $rect */
    public function record(int $w, int $h, array $rect, string $text, string $artifactPath): void
    {
        $this->canvasW = $w;
        $this->canvasH = $h;
        $this->rectPx = $rect;
        $this->text = $text;
        $this->artifactPath = $artifactPath;
    }

    public function asMediaAreaCoordinates(): array
    {
        if ($this->canvasW === null || $this->canvasH === null || $this->rectPx === null) {
            throw new RuntimeException('No rendered plaque to derive media-area coordinates from.');
        }

        return [
            'x' => round($this->rectPx['x'] / $this->canvasW * 100.0, 1),
            'y' => round($this->rectPx['y'] / $this->canvasH * 100.0, 1),
            'w' => round($this->rectPx['w'] / $this->canvasW * 100.0, 1),
            'h' => round($this->rectPx['h'] / $this->canvasH * 100.0, 1),
            'rotation' => 0.0,
            'radius' => CtaPlaqueCompositor::RECT['radius'],
        ];
    }
}
