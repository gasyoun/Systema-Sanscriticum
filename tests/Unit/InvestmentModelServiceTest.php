<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InvestmentModelService;
use Tests\TestCase;

/**
 * Инвест-модель (H261, фаза E). Две группы проверок:
 *  1. Чистая финансовая математика (NPV/IRR/аннуитет/окупаемость) на примерах,
 *     считаемых руками.
 *  2. Валидация на опубликованном кейсе «Юный чемпион»: капзатраты 14,5 млн,
 *     аренда 160k/мес → IRR ~1 %, окупаемость к 6-му году. Сходимость с кейсом =
 *     проверка корректности формул (критерий приёмки H261).
 */
class InvestmentModelServiceTest extends TestCase
{
    private function service(): InvestmentModelService
    {
        // evaluate()/статические методы деп не трогают (живые данные — только в
        // defaults()), поэтому реальные сервисы из контейнера безопасны и без БД.
        return app(InvestmentModelService::class);
    }

    // ── Чистая математика ────────────────────────────────────────────────────

    public function test_npv_discounts_correctly(): void
    {
        // −100 сейчас, +110 через год: при 10 % NPV ровно 0, при 0 % = +10.
        $this->assertEqualsWithDelta(0.0, InvestmentModelService::npv(0.10, [-100, 110]), 1e-9);
        $this->assertEqualsWithDelta(10.0, InvestmentModelService::npv(0.0, [-100, 110]), 1e-9);
    }

    public function test_irr_single_period_is_exact(): void
    {
        $irr = InvestmentModelService::irr([-100, 110]);
        $this->assertNotNull($irr);
        $this->assertEqualsWithDelta(0.10, $irr, 1e-4);
    }

    public function test_irr_makes_npv_zero(): void
    {
        $cf = [-1000, 500, 500, 500];
        $irr = InvestmentModelService::irr($cf);
        $this->assertNotNull($irr);
        // По определению: NPV при ставке IRR ≈ 0.
        $this->assertEqualsWithDelta(0.0, InvestmentModelService::npv($irr, $cf), 1e-3);
        $this->assertEqualsWithDelta(0.2337, $irr, 1e-3);
    }

    public function test_irr_null_when_no_sign_change(): void
    {
        // Поток без оттока (все притоки) — IRR не определена.
        $this->assertNull(InvestmentModelService::irr([100, 50]));
    }

    public function test_annuity_factor(): void
    {
        $this->assertEqualsWithDelta(2.48685, InvestmentModelService::annuityFactor(0.10, 3), 1e-4);
        // r ≈ 0 → множитель = число периодов.
        $this->assertEqualsWithDelta(5.0, InvestmentModelService::annuityFactor(0.0, 5), 1e-9);
    }

    public function test_payback_time_interpolates(): void
    {
        // −1000, затем +400/год: окупается в 3-й год, дробно 2.5.
        $pb = InvestmentModelService::paybackTime([-1000, 400, 400, 400, 400]);
        $this->assertSame(3, $pb['year']);
        $this->assertEqualsWithDelta(2.5, $pb['time'], 1e-9);
    }

    public function test_payback_null_when_never_recovered(): void
    {
        $pb = InvestmentModelService::paybackTime([-1000, 100, 100]);
        $this->assertNull($pb['year']);
        $this->assertNull($pb['time']);
    }

    // ── Валидация на кейсе «Юный чемпион» (критерий приёмки) ──────────────────

    /**
     * «Без покупки»: капзатраты 14,5 млн, аренда 160k/мес (1,92 млн/год),
     * доп. выручка 4,422 млн/год → чистый поток 2,502 млн/год, горизонт 6.
     * Ожидание кейса: IRR ~1 %, окупаемость к 6-му году, NPV(1 %) ≈ 0.
     */
    public function test_yuny_champion_scenario_matches_published_case(): void
    {
        $svc = $this->service();

        $res = $svc->evaluate([
            'label' => 'Без покупки здания',
            'capex' => 14_500_000,
            'annual_revenue' => 4_422_000,
            'annual_expense' => 1_920_000,
            'growth_pct' => 0,
            'horizon_years' => 6,
            'discount_rate' => 0.01, // сверяем IRR/NPV на ставке 1 %
        ]);

        // IRR ~1 % (критерий приёмки).
        $this->assertNotNull($res['irr_pct']);
        $this->assertGreaterThanOrEqual(0.9, $res['irr_pct']);
        $this->assertLessThanOrEqual(1.2, $res['irr_pct']);

        // Окупаемость к 6-му году (критерий приёмки).
        $this->assertSame(6, $res['payback_year']);
        $this->assertEqualsWithDelta(5.8, $res['payback_time'], 0.1);

        // При ставке 1 % NPV ≈ 0 (проект едва оправдан) — сходимость формул.
        $this->assertEqualsWithDelta(0.0, $res['npv'], 5_000);
    }

    /**
     * Тот же кейс при нормальной стоимости капитала (20 %): NPV резко
     * отрицательный, вердикт — «отказаться» (главный вывод финдира в кейсе).
     */
    public function test_yuny_champion_verdict_is_reject_at_market_rate(): void
    {
        $res = $this->service()->evaluate([
            'label' => 'Без покупки здания',
            'capex' => 14_500_000,
            'annual_revenue' => 4_422_000,
            'annual_expense' => 1_920_000,
            'horizon_years' => 6,
            'discount_rate' => 0.20,
        ]);

        $this->assertLessThan(0, $res['npv']);
        $this->assertSame('danger', $res['level']);
        $this->assertStringContainsString('Отказаться', $res['verdict']);
        // Точка безубыточности выше фактического потока — проект не дотягивает.
        $this->assertFalse($res['meets_breakeven']);
        $this->assertEqualsWithDelta(4_360_000, $res['breakeven_annual_net'], 20_000);
    }

    public function test_clearly_profitable_project_is_green(): void
    {
        $res = $this->service()->evaluate([
            'label' => 'Хороший проект',
            'capex' => 1_000_000,
            'annual_revenue' => 600_000,
            'annual_expense' => 100_000,
            'horizon_years' => 5,
            'discount_rate' => 0.10,
        ]);

        $this->assertGreaterThan(0, $res['npv']);
        $this->assertSame(2, $res['payback_year']);
        $this->assertSame('success', $res['level']);
    }

    public function test_growth_increases_later_year_revenue(): void
    {
        $res = $this->service()->evaluate([
            'capex' => 1_000_000,
            'annual_revenue' => 1_000_000,
            'annual_expense' => 0,
            'growth_pct' => 10,
            'horizon_years' => 3,
            'discount_rate' => 0.10,
        ]);

        // Год 1 = 1 млн, год 2 = 1,1 млн, год 3 = 1,21 млн (рост 10 %).
        $this->assertEqualsWithDelta(1_000_000, $res['rows'][1]['revenue'], 1);
        $this->assertEqualsWithDelta(1_100_000, $res['rows'][2]['revenue'], 1);
        $this->assertEqualsWithDelta(1_210_000, $res['rows'][3]['revenue'], 1);
    }

    public function test_compare_picks_higher_npv(): void
    {
        $svc = $this->service();
        $a = $svc->evaluate(['label' => 'A', 'capex' => 1_000_000, 'annual_revenue' => 500_000, 'annual_expense' => 0, 'horizon_years' => 5, 'discount_rate' => 0.10]);
        $b = $svc->evaluate(['label' => 'B', 'capex' => 1_000_000, 'annual_revenue' => 300_000, 'annual_expense' => 0, 'horizon_years' => 5, 'discount_rate' => 0.10]);

        $this->assertSame('A', $svc->compare($a, $b)['winner']);
    }
}
