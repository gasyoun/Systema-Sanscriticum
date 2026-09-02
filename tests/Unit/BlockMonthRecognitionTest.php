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

    // ---------------------------------------------------------------- H3951
    // Сторож ШТАМПОВАННОГО ПРОГОНА. Форма курса 266 с прода (02-09-2026):
    // блоки 1..50 стоят одним штампом 2025-03-14 (тот же штамп — на курсах
    // 334/356/357/366, это отметка миграции), блоки 51..70 идут настоящим
    // 28-дневным циклом.

    /** @return array<int, string> */
    private function course266BlockDates(): array
    {
        $dates = [];
        for ($n = 1; $n <= 50; $n++) {
            $dates[$n] = '2025-03-14';
        }
        $day = new \DateTimeImmutable('2025-06-08');
        for ($n = 51; $n <= 70; $n++) {
            $dates[$n] = $day->format('Y-m-d');
            $day = $day->modify('+28 days');
        }

        return $dates;
    }

    public function test_covered_run_on_one_stamped_date_is_stamped(): void
    {
        $this->assertTrue(BlockMonthRecognition::coveredRunIsStamped(
            [1, 2, 3],
            [1 => '2025-03-14', 2 => '2025-03-14', 3 => '2025-03-14'],
        ));
    }

    public function test_single_covered_block_is_never_stamped(): void
    {
        // Одноблочный платёж — 8820 строк популяции. Месяц признания там
        // однозначен, сторож обязан пройти мимо.
        $this->assertFalse(BlockMonthRecognition::coveredRunIsStamped(
            [7],
            [7 => '2025-03-14'],
        ));
    }

    public function test_real_schedule_is_not_stamped(): void
    {
        $this->assertFalse(BlockMonthRecognition::coveredRunIsStamped(
            [51, 52, 53],
            $this->course266BlockDates(),
        ));
    }

    public function test_undated_covered_block_is_not_stamped(): void
    {
        // Хотя бы один покрытый блок без даты — не штамп: distribute() и так
        // роняет его долю в месяц оплаты.
        $this->assertFalse(BlockMonthRecognition::coveredRunIsStamped(
            [1, 2, 3],
            [1 => '2025-03-14', 3 => '2025-03-14'],
        ));
    }

    public function test_course_266_prepayment_over_the_stamped_run_is_stamped(): void
    {
        // Предоплата на 36 блоков вперёд: покрытые 1..36 целиком лежат внутри
        // штампованного прогона 1..50.
        $this->assertTrue(BlockMonthRecognition::coveredRunIsStamped(
            range(1, 36),
            $this->course266BlockDates(),
        ));
    }

    public function test_run_crossing_out_of_the_stamp_is_not_stamped(): void
    {
        $this->assertFalse(BlockMonthRecognition::coveredRunIsStamped(
            range(45, 55),
            $this->course266BlockDates(),
        ));
    }

    public function test_course_level_degeneracy_alone_does_not_trigger_the_guard(): void
    {
        // Курс 266 покурсово НЕ вырожден (70 датированных блоков на 21 дате) —
        // покурсовой предикат промахнулся бы мимо самого дефекта. Предикат
        // поблочный именно поэтому.
        $dates = $this->course266BlockDates();
        $this->assertGreaterThan(1, count(array_unique($dates)));
        $this->assertTrue(BlockMonthRecognition::coveredRunIsStamped(range(1, 36), $dates));
    }

    public function test_attribute_with_guard_off_keeps_pre_h3951_shares_but_names_the_stamp(): void
    {
        $dates = $this->course266BlockDates();
        $months = array_map(static fn (string $d): string => substr($d, 0, 7), $dates);

        $out = BlockMonthRecognition::attribute(
            144000.0,
            null,
            range(1, 36),
            $months,
            $dates,
            '2026-08',
            false,
        );

        // Поведение до H3951 байт-в-байт: вся сумма в месяц штампа, на 17
        // месяцев назад от даты предоплаты.
        $this->assertSame(['2025-03'], array_keys($out['shares']));
        $this->assertEqualsWithDelta(144000.0, $out['shares']['2025-03'], 0.0000001);
        $this->assertSame(BlockMonthRecognition::BY_BLOCKS, $out['mechanism']);
        // …но строка уже видна аудиту как штампованная — named fallback, не
        // тихое смешение.
        $this->assertTrue($out['stamped']);
    }

    public function test_attribute_with_guard_on_moves_the_stamped_run_to_the_payment_month(): void
    {
        $dates = $this->course266BlockDates();
        $months = array_map(static fn (string $d): string => substr($d, 0, 7), $dates);

        $out = BlockMonthRecognition::attribute(
            144000.0,
            null,
            range(1, 36),
            $months,
            $dates,
            '2026-08',
            true,
        );

        $this->assertSame(['2026-08' => 144000.0], $out['shares']);
        $this->assertSame(BlockMonthRecognition::BY_STAMPED_RUN, $out['mechanism']);
        $this->assertTrue($out['stamped']);
    }

    public function test_attribute_real_schedule_is_untouched_by_the_guard(): void
    {
        $dates = $this->course266BlockDates();
        $months = array_map(static fn (string $d): string => substr($d, 0, 7), $dates);

        $off = BlockMonthRecognition::attribute(9600.0, null, [51, 52], $months, $dates, '2026-08', false);
        $on = BlockMonthRecognition::attribute(9600.0, null, [51, 52], $months, $dates, '2026-08', true);

        $this->assertSame($off['shares'], $on['shares']);
        $this->assertSame(BlockMonthRecognition::BY_BLOCKS, $on['mechanism']);
        $this->assertFalse($on['stamped']);
    }

    public function test_column_override_wins_over_the_stamp(): void
    {
        $dates = $this->course266BlockDates();
        $months = array_map(static fn (string $d): string => substr($d, 0, 7), $dates);

        $out = BlockMonthRecognition::attribute(144000.0, '2026-08', range(1, 36), $months, $dates, '2026-08', true);

        $this->assertSame(['2026-08' => 144000.0], $out['shares']);
        $this->assertSame(BlockMonthRecognition::BY_COLUMN, $out['mechanism']);
        $this->assertFalse($out['stamped']);
    }

    public function test_attribute_without_covered_blocks_falls_to_created_month(): void
    {
        $out = BlockMonthRecognition::attribute(500.0, null, [], [], [], '2026-08', true);

        $this->assertSame(['2026-08' => 500.0], $out['shares']);
        $this->assertSame(BlockMonthRecognition::BY_CREATED, $out['mechanism']);
        $this->assertFalse($out['stamped']);
    }
}
