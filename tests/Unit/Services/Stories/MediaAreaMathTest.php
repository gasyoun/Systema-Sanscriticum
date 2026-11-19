<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Stories;

use App\Services\Anons\CtaPlaqueCompositor;
use App\Services\Anons\PlaqueBounds;
use App\Support\MediaAreaMath;
use PHPUnit\Framework\TestCase;

/**
 * H6678: координатная математика mediaAreaCoordinates сториз.
 *
 * Telegram-семантика (layer 225): mediaAreaCoordinates.x/y — ЦЕНТР зоны
 * клика в процентах холста. Все прежние конвертации держали y как верхнюю
 * кромку отрисованной плашки → клик-зона ложилась на пол-плашки выше
 * (живой тап-тест 13-10-2026: плашка 352..442 из 640px, кликалась только
 * 352..397 — ровно пересечение зоны [48..62] с плашкой [55..69]).
 *
 * Фикстура — измеренная плашка сториз 1474 v3 (1474_plaque_v3.mp4):
 * box 40,307 281x90 на холсте 360x640.
 */
final class MediaAreaMathTest extends TestCase
{
    /** Фикстура сториз 1474 v3: px-бокс отрисованной плашки на 360x640. */
    private const PLAQUE_PX = ['left' => 40.0, 'top' => 307.0, 'w' => 281.0, 'h' => 90.0];

    private const CANVAS = ['w' => 360.0, 'h' => 640.0];

    /** @test */
    public function plaque_px_box_maps_to_center_semantics(): void
    {
        $area = MediaAreaMath::fromPlaqueBoxPx(40.0, 307.0, 281.0, 90.0, 360.0, 640.0);

        // x-центр = 40 + 281/2 = 180.5px → 50.1%; y-центр = 307 + 45 = 352px → 55.0%.
        self::assertSame(50.1, $area['x']);
        self::assertSame(55.0, $area['y']);
        self::assertSame(78.1, $area['w']);
        self::assertSame(14.1, $area['h']);
        self::assertSame(0.0, $area['rotation']);
        self::assertSame(MediaAreaMath::RADIUS, $area['radius']);
    }

    /** @test */
    public function y_center_is_top_plus_half_height_not_the_top_edge(): void
    {
        $area = MediaAreaMath::fromPlaqueBoxPx(0.0, 55.0, 100.0, 14.0, 100.0, 100.0);

        // Верхняя кромка 55% при h=14% → центр 62%, НЕ 55% (прошлый баг).
        self::assertSame(62.0, $area['y']);
    }

    /** @test */
    public function legacy_layout_rect_derives_center_y_62(): void
    {
        $area = MediaAreaMath::legacyCenterArea();

        // Легаси #2643 в семантике отрисовки: y=55 — верхняя кромка, h=14.
        self::assertSame(50.0, $area['x']);
        self::assertSame(62.0, $area['y']);
        self::assertSame(78.0, $area['w']);
        self::assertSame(14.0, $area['h']);
        self::assertSame(7.0, $area['radius']);
    }

    /** @test */
    public function round_trip_center_area_restores_the_measured_plaque_rect(): void
    {
        // Парити-пруф: burned rect (px) ↔ sent media_area (center-%) ↔ back (px).
        $area = MediaAreaMath::fromPlaqueBoxPx(
            self::PLAQUE_PX['left'], self::PLAQUE_PX['top'], self::PLAQUE_PX['w'], self::PLAQUE_PX['h'],
            self::CANVAS['w'], self::CANVAS['h'],
        );
        $restored = MediaAreaMath::toPlaqueBoxPx($area, self::CANVAS['w'], self::CANVAS['h']);

        self::assertEqualsWithDelta(self::PLAQUE_PX['left'], $restored['left'], 0.5);
        self::assertEqualsWithDelta(self::PLAQUE_PX['top'], $restored['top'], 0.5);
        self::assertEqualsWithDelta(self::PLAQUE_PX['w'], $restored['w'], 0.5);
        self::assertEqualsWithDelta(self::PLAQUE_PX['h'], $restored['h'], 0.5);
    }

    /** @test */
    public function plaque_bounds_emit_center_semantics_for_the_measured_rect(): void
    {
        // PlaqueBounds объявлена в одном файле с CtaPlaqueCompositor —
        // автозагрузчик знает файл по первому классу.
        class_exists(CtaPlaqueCompositor::class);

        $bounds = new PlaqueBounds;
        $bounds->record(360, 640, ['x' => 40, 'y' => 307, 'w' => 281, 'h' => 90], 'Онлайн-курс', '/tmp/x.jpg');

        $area = $bounds->asMediaAreaCoordinates();

        self::assertSame(55.0, $area['y'], 'y — ЦЕНТР плашки (307+45=352px → 55%), не верхняя кромка');
        self::assertSame(50.1, $area['x']);
        self::assertSame(78.1, $area['w']);
        self::assertSame(14.1, $area['h']);
    }

    /** @test */
    public function compositor_pixel_rect_top_edge_matches_legacy_layout(): void
    {
        // Отрисовка НЕ меняется: y=55% остаётся ВЕРХНЕЙ кромкой на холсте 360x640.
        $rect = (new CtaPlaqueCompositor)->pixelRect(360, 640);

        self::assertSame(352, $rect['y']); // round(0.55 * 640) — верхняя кромка; тап-тест 13-10: 352..442
        self::assertSame(281, $rect['w']);
        self::assertSame(90, $rect['h']);
    }

    /** @test */
    public function validator_accepts_only_valid_center_rectangles(): void
    {
        self::assertTrue(MediaAreaMath::isValidArea(['x' => 50.1, 'y' => 55.0, 'w' => 78.1, 'h' => 14.1]));
        self::assertTrue(MediaAreaMath::isValidArea(['x' => '50', 'y' => '62', 'w' => '78', 'h' => '14']));
        self::assertTrue(MediaAreaMath::isValidArea([
            'x' => 50.1, 'y' => 55.0, 'w' => 78.1, 'h' => 14.1, 'rotation' => 5.0, 'radius' => 3.0,
        ]));

        self::assertFalse(MediaAreaMath::isValidArea(null));
        self::assertFalse(MediaAreaMath::isValidArea('not-an-array'));
        self::assertFalse(MediaAreaMath::isValidArea(['x' => 50.0, 'y' => 55.0, 'w' => 78.0]));
        self::assertFalse(MediaAreaMath::isValidArea(['x' => 50.0, 'y' => 55.0, 'w' => 0.0, 'h' => 14.0]), 'w>0 обязателен');
        self::assertFalse(MediaAreaMath::isValidArea(['x' => 50.0, 'y' => 55.0, 'w' => 78.0, 'h' => 0.0]), 'h>0 обязателен');
        self::assertFalse(MediaAreaMath::isValidArea(['x' => -1.0, 'y' => 55.0, 'w' => 78.0, 'h' => 14.0]), 'x в [0..100]');
        self::assertFalse(MediaAreaMath::isValidArea(['x' => 50.0, 'y' => 101.0, 'w' => 78.0, 'h' => 14.0]), 'y в [0..100]');
        self::assertFalse(MediaAreaMath::isValidArea(['x' => 'abc', 'y' => 55.0, 'w' => 78.0, 'h' => 14.0]), 'числа, не строки');
    }

    /** @test */
    public function normalize_fills_rotation_and_radius_or_throws(): void
    {
        $area = MediaAreaMath::normalize(['x' => '50.1', 'y' => '55.0', 'w' => '78.1', 'h' => '14.1']);
        self::assertSame(50.1, $area['x']);
        self::assertSame(0.0, $area['rotation']);
        self::assertSame(MediaAreaMath::RADIUS, $area['radius']);

        $this->expectException(\RuntimeException::class);
        MediaAreaMath::normalize(['x' => 50.0]);
    }

    /** @test */
    public function zero_canvas_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MediaAreaMath::fromPlaqueBoxPx(0.0, 0.0, 10.0, 10.0, 0.0, 640.0);
    }
}
