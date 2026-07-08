<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BlockMonthRecognition;
use PHPUnit\Framework\TestCase;

/**
 * Контракт канонического ядра «оплаченный блок → месяц признания» (H349),
 * общего для начисления ЗП (TeacherSalaryService) и признания выручки
 * (RevenueRecognitionService). Проверяем именно те инварианты, которые оба
 * сервиса раньше держали в продублированном виде.
 */
class BlockMonthRecognitionTest extends TestCase
{
    public function test_null_range_covers_all_course_blocks(): void
    {
        $this->assertSame(
            [1, 2, 3, 4],
            BlockMonthRecognition::coveredBlockNumbers(null, null, [1, 2, 3, 4]),
        );
    }

    public function test_bounded_range_filters_listed_blocks(): void
    {
        $this->assertSame(
            [2, 3],
            BlockMonthRecognition::coveredBlockNumbers(2, 3, [1, 2, 3, 4]),
        );
    }

    public function test_open_ended_start_covers_from_block_onward(): void
    {
        $this->assertSame(
            [3, 4],
            BlockMonthRecognition::coveredBlockNumbers(3, null, [1, 2, 3, 4]),
        );
    }

    public function test_open_ended_end_covers_up_to_block(): void
    {
        $this->assertSame(
            [1, 2],
            BlockMonthRecognition::coveredBlockNumbers(null, 2, [1, 2, 3, 4]),
        );
    }

    public function test_range_beyond_listed_blocks_falls_back_to_direct_range(): void
    {
        // Платёж за блоки 5..6, которых нет в списке заведённых блоков курса —
        // берём прямой диапазон, а не пустоту.
        $this->assertSame(
            [5, 6],
            BlockMonthRecognition::coveredBlockNumbers(5, 6, [1, 2, 3, 4]),
        );
    }

    public function test_reversed_range_is_normalized_in_fallback(): void
    {
        $this->assertSame(
            [5, 6, 7],
            BlockMonthRecognition::coveredBlockNumbers(7, 5, []),
        );
    }

    public function test_distribute_splits_amount_equally_by_block_month(): void
    {
        $out = BlockMonthRecognition::distribute(
            300.0,
            [1, 2, 3],
            [1 => '2026-01', 2 => '2026-02', 3 => '2026-03'],
            '2026-01',
        );

        $this->assertSame(['2026-01' => 100.0, '2026-02' => 100.0, '2026-03' => 100.0], $out);
    }

    public function test_distribute_aggregates_blocks_sharing_a_month(): void
    {
        $out = BlockMonthRecognition::distribute(
            300.0,
            [1, 2, 3],
            [1 => '2026-01', 2 => '2026-01', 3 => '2026-02'],
            '2026-01',
        );

        $this->assertSame(['2026-01' => 200.0, '2026-02' => 100.0], $out);
    }

    public function test_distribute_falls_back_to_created_month_for_undated_blocks(): void
    {
        $out = BlockMonthRecognition::distribute(
            200.0,
            [1, 2],
            [1 => '2026-05'],
            '2025-12',
        );

        $this->assertSame(['2026-05' => 100.0, '2025-12' => 100.0], $out);
    }

    public function test_distribute_preserves_sum_invariant(): void
    {
        // Σ долей == сумме платежа — денежный инвариант, на котором стоит совпадение
        // накопительной и кассовой выручки.
        $out = BlockMonthRecognition::distribute(
            100.0,
            [1, 2, 3, 4, 5, 6, 7],
            [],
            '2026-04',
        );

        // 7 × (100/7) не даёт ровно 100.0 в плавающей арифметике — инвариант
        // держится с точностью до эпсилона (денежное округление — забота Money).
        $this->assertSame(['2026-04'], array_keys($out));
        $this->assertEqualsWithDelta(100.0, array_sum($out), 0.0000001);
        $this->assertEqualsWithDelta(100.0, $out['2026-04'], 0.0000001);
    }
}
