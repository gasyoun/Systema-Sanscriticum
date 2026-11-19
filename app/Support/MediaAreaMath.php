<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * H6678: математика mediaAreaCoordinates сториз — ЕДИНАЯ точка истины.
 *
 * Telegram-семантика mediaAreaCoordinates (TL layer 225, core.telegram.org/api/stories#media-areas):
 * x и y — ЦЕНТР зоны клика в процентах холста, w/h — размеры зоны в процентах,
 * радиус — скругление углов. Всё, что считало x/y левой-верхней кромкой,
 * сдвигало клик-зону на пол-плашки вверх (живой тап-тест 13-10-2026:
 * отрисованная плашка 352..442 из 640px, кликалась только 352..397).
 *
 * Отрисовка плашки в пикселях (compositor) остаётся в топ-левой семантике —
 * здесь конвертируется ИЗМЕРЕННЫЙ прямоугольник в центр-семантику media_area
 * и обратно (парити-пруф теста).
 */
final class MediaAreaMath
{
    /** Скругление углов зоны (проценты) — форма, живущая в StoryPublisher::send. */
    public const RADIUS = 7.0;

    /**
     * Легаси-прямоугольник #2643 в топ-левой семантике ОТРИСОВКИ:
     * x=50 — центр по X, y=55 — верхняя кромка, w=78/h=14 — размеры (%).
     * В center-семантике media_area y-центр = 55 + 14/2 = 62.
     */
    public const LEGACY_TOP_LEFT = ['x' => 50.0, 'y' => 55.0, 'w' => 78.0, 'h' => 14.0];

    /**
     * Измеренный прямоугольник плашки (пиксели, left/top/w/h на холсте
     * canvasW×canvasH) → mediaAreaCoordinates в center-семантике.
     *
     * @return array{x: float, y: float, w: float, h: float, rotation: float, radius: float}
     */
    public static function fromPlaqueBoxPx(float $left, float $top, float $w, float $h, float $canvasW, float $canvasH): array
    {
        if ($canvasW <= 0 || $canvasH <= 0) {
            throw new InvalidArgumentException('Canvas dimensions must be positive.');
        }

        return [
            'x' => round(($left + $w / 2.0) / $canvasW * 100.0, 1),
            'y' => round(($top + $h / 2.0) / $canvasH * 100.0, 1),
            'w' => round($w / $canvasW * 100.0, 1),
            'h' => round($h / $canvasH * 100.0, 1),
            'rotation' => 0.0,
            'radius' => self::RADIUS,
        ];
    }

    /**
     * Легаси-прямоугольник #2643, пересчитанный в center-семантику:
     * (50, 62, 78, 14). Легаси-полоса stories:publish-story без override
     * идёт через это значение — прежний y=55 как верхняя кромка клал зону
     * на пол-плашки выше отрисованной (тап-тест 13-10-2026).
     *
     * @return array{x: float, y: float, w: float, h: float, rotation: float, radius: float}
     */
    public static function legacyCenterArea(): array
    {
        return [
            'x' => self::LEGACY_TOP_LEFT['x'],
            'y' => round(self::LEGACY_TOP_LEFT['y'] + self::LEGACY_TOP_LEFT['h'] / 2.0, 1),
            'w' => self::LEGACY_TOP_LEFT['w'],
            'h' => self::LEGACY_TOP_LEFT['h'],
            'rotation' => 0.0,
            'radius' => self::RADIUS,
        ];
    }

    /**
     * Обратное преобразование (парити-пруф): center-семантика media_area →
     * прямоугольник в пикселях холста (left/top/w/h).
     *
     * @param  array{x: float|int, y: float|int, w: float|int, h: float|int}  $area
     * @return array{left: float, top: float, w: float, h: float}
     */
    public static function toPlaqueBoxPx(array $area, float $canvasW, float $canvasH): array
    {
        if ($canvasW <= 0 || $canvasH <= 0) {
            throw new InvalidArgumentException('Canvas dimensions must be positive.');
        }

        $w = ((float) $area['w']) / 100.0 * $canvasW;
        $h = ((float) $area['h']) / 100.0 * $canvasH;

        return [
            'left' => ((float) $area['x']) / 100.0 * $canvasW - $w / 2.0,
            'top' => ((float) $area['y']) / 100.0 * $canvasH - $h / 2.0,
            'w' => $w,
            'h' => $h,
        ];
    }

    /**
     * Валидатор per-story override (story_posts.media_area / pending-контекст):
     * структурно это валидный center-прямоугольник или нет. Fail-closed —
     * битый override отбрасывается, публикация идёт легаси-зоной.
     */
    public static function isValidArea(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (['x', 'y', 'w', 'h'] as $key) {
            if (! isset($value[$key]) || ! is_numeric($value[$key])) {
                return false;
            }

            $v = (float) $value[$key];
            if ($v < 0.0 || $v > 100.0) {
                return false;
            }
        }

        return (float) $value['w'] > 0.0 && (float) $value['h'] > 0.0;
    }

    /**
     * Нормализовать override к канонической форме (float, rotation/radius
     * дополнены) или throw — для путей, где битый override обязан падать громко.
     *
     * @return array{x: float, y: float, w: float, h: float, rotation: float, radius: float}
     */
    public static function normalize(mixed $value): array
    {
        if (! self::isValidArea($value)) {
            throw new RuntimeException('media_area override is not a valid center-semantics rectangle.');
        }

        return [
            'x' => (float) $value['x'],
            'y' => (float) $value['y'],
            'w' => (float) $value['w'],
            'h' => (float) $value['h'],
            'rotation' => (float) ($value['rotation'] ?? 0.0),
            'radius' => (float) ($value['radius'] ?? self::RADIUS),
        ];
    }
}
