<?php

namespace Tests\Unit;

use App\Filament\Pages\TeacherSalaries;
use Tests\TestCase;

class WhatIfPercentTest extends TestCase
{
    /** @test */
    public function percent_scenario_beats_fixed_when_revenue_share_is_higher(): void
    {
        // Выручка блока 100 000, сравниваем с 30%, коэф 100% (без банка).
        // Фикс-база 20 000. Процент = 30 000 → дороже школе на 10 000.
        $r = TeacherSalaries::whatIfPercentBreakdown(100000, 30, 100, 20000);

        $this->assertSame(30000.0, $r['percent']);
        $this->assertSame(20000.0, $r['fixed']);
        $this->assertSame(10000.0, $r['delta']);
    }

    /** @test */
    public function coefficient_applies_symmetrically_to_both_scenarios(): void
    {
        // Коэф 92% (эквайринг) домножает обе величины одинаково.
        $r = TeacherSalaries::whatIfPercentBreakdown(100000, 30, 92, 20000);

        $this->assertSame(27600.0, $r['percent']); // 30000 × 0.92
        $this->assertSame(18400.0, $r['fixed']);    // 20000 × 0.92
        $this->assertSame(9200.0, $r['delta']);     // 10000 × 0.92
    }

    /** @test */
    public function fixed_is_cheaper_gives_negative_delta(): void
    {
        // Низкая выручка: процент даёт меньше фикса → delta < 0 (фикс дешевле).
        $r = TeacherSalaries::whatIfPercentBreakdown(30000, 30, 100, 20000);

        $this->assertSame(9000.0, $r['percent']);
        $this->assertSame(-11000.0, $r['delta']);
    }
}
