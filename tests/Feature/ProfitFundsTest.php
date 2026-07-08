<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DelegationKpiService;
use App\Services\ProfitFundsService;
use App\Support\FinanceCockpitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ручная сверка системы фондов прибыли и KPI-панели делегирования (H259, фаза D
 * плана noboring /cases/education) на известной синтетической прибыли.
 *
 * ОПиУ/ДДС считаются другими фазами (проверены отдельно); чтобы тест фазы D был
 * детерминирован, подменяем FinanceCockpitReport контролируемым дублёром, задающим
 * прибыль (EBITDA) и чистый поток ДДС по месяцам. Так проверяем именно логику
 * распределения по фондам и сверки резерва с кассой.
 */
class ProfitFundsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

        // Доли фондов и окно — фиксируем, чтобы тест не зависел от .env.
        config()->set('profit_funds.funds', [
            'dividends' => ['label' => 'Дивиденды', 'hint' => '', 'share' => 0.60],
            'reserve' => ['label' => 'Резерв', 'hint' => '', 'share' => 0.20],
            'team' => ['label' => 'Команда', 'hint' => '', 'share' => 0.10],
            'company' => ['label' => 'Компания', 'hint' => '', 'share' => 0.10],
        ]);
        config()->set('profit_funds.reserve_key', 'reserve');
        config()->set('profit_funds.accumulation_window_months', 3);
        config()->set('profit_funds.reserve_cash_warn_ratio', 0.8);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Подменить FinanceCockpitReport дублёром с заданными прибылью и кассой по
     * месяцам. Ключ — 'YYYY-MM'; значение — ['ebitda' => .., 'net' => ..].
     *
     * @param  array<string, array{ebitda:float, net:float}>  $byMonth
     */
    private function fakeReport(array $byMonth): void
    {
        $fake = new class($byMonth) extends FinanceCockpitReport
        {
            /** @param array<string, array{ebitda:float, net:float}> $byMonth */
            public function __construct(private array $byMonth) {}

            public function opiuAccrual(string $period): array
            {
                $ebitda = (float) ($this->byMonth[$period]['ebitda'] ?? 0.0);

                return ['ebitda' => $ebitda, 'ebitdaMargin' => null, 'revenue' => 0.0];
            }

            public function dds(string $period): array
            {
                return ['net' => (float) ($this->byMonth[$period]['net'] ?? 0.0)];
            }

            public function deferredRevenue(string $period): array
            {
                return ['cashReceived' => 0.0, 'recognized' => 0.0, 'deferred' => 0.0];
            }
        };

        $this->app->instance(FinanceCockpitReport::class, $fake);
    }

    public function test_distributes_current_month_profit_by_shares(): void
    {
        $this->fakeReport(['2026-07' => ['ebitda' => 1_000_000, 'net' => 1_000_000]]);

        $snap = $this->app->make(ProfitFundsService::class)->snapshot();

        $this->assertSame(1_000_000.0, $snap['current_profit']);
        $this->assertTrue($snap['current_profit_positive']);
        $this->assertSame(600_000.0, $snap['current_allocations']['dividends']);
        $this->assertSame(200_000.0, $snap['current_allocations']['reserve']);
        $this->assertSame(100_000.0, $snap['current_allocations']['team']);
        $this->assertSame(100_000.0, $snap['current_allocations']['company']);
    }

    public function test_loss_month_does_not_fund(): void
    {
        $this->fakeReport(['2026-07' => ['ebitda' => -500_000, 'net' => -500_000]]);

        $snap = $this->app->make(ProfitFundsService::class)->snapshot();

        $this->assertFalse($snap['current_profit_positive']);
        $this->assertSame(0.0, $snap['current_allocations']['dividends']);
        $this->assertSame(0.0, $snap['current_allocations']['reserve']);
    }

    public function test_accumulates_over_window_positive_months_only(): void
    {
        // Окно = 3 мес: май(+300k), июнь(−100k, убыток), июль(+200k).
        $this->fakeReport([
            '2026-05' => ['ebitda' => 300_000, 'net' => 300_000],
            '2026-06' => ['ebitda' => -100_000, 'net' => -100_000],
            '2026-07' => ['ebitda' => 200_000, 'net' => 200_000],
        ]);

        $snap = $this->app->make(ProfitFundsService::class)->snapshot();

        // Резерв 20% от суммы прибыльных месяцев (300k + 200k = 500k) = 100k.
        $this->assertSame(100_000.0, $snap['accumulated']['reserve']);
        $this->assertSame(300_000.0, $snap['accumulated']['dividends']);
        $this->assertSame(500_000.0, $snap['accumulated_profit']);
        $this->assertCount(3, $snap['months']);
    }

    public function test_reserve_backed_by_cash_is_success(): void
    {
        // Прибыль всех 3 мес по 200k (резерв накоплен 3×40k=120k); касса > резерва.
        $this->fakeReport([
            '2026-05' => ['ebitda' => 200_000, 'net' => 200_000],
            '2026-06' => ['ebitda' => 200_000, 'net' => 200_000],
            '2026-07' => ['ebitda' => 200_000, 'net' => 200_000],
        ]);

        $snap = $this->app->make(ProfitFundsService::class)->snapshot();

        $this->assertSame(120_000.0, $snap['reserve_accrued']);
        $this->assertSame(600_000.0, $snap['accumulated_cash']);
        $this->assertSame('success', $snap['reserve_level']);
    }

    public function test_reserve_not_backed_by_cash_is_danger(): void
    {
        // Прибыль есть (резерв копится), но касса ушла в минус (кассовый разрыв):
        // резерв «на бумаге» → красный.
        $this->fakeReport([
            '2026-05' => ['ebitda' => 200_000, 'net' => -50_000],
            '2026-06' => ['ebitda' => 200_000, 'net' => -50_000],
            '2026-07' => ['ebitda' => 200_000, 'net' => -50_000],
        ]);

        $snap = $this->app->make(ProfitFundsService::class)->snapshot();

        $this->assertSame(120_000.0, $snap['reserve_accrued']);
        $this->assertTrue($snap['accumulated_cash'] < 0);
        $this->assertSame('danger', $snap['reserve_level']);
    }

    public function test_no_profit_in_window_is_gray(): void
    {
        $this->fakeReport(['2026-07' => ['ebitda' => 0, 'net' => 0]]);

        $snap = $this->app->make(ProfitFundsService::class)->snapshot();

        $this->assertSame(0.0, $snap['reserve_accrued']);
        $this->assertSame('gray', $snap['reserve_level']);
    }

    public function test_shares_not_summing_to_one_are_normalized_and_noted(): void
    {
        // Доли дают 120% — сервис нормирует к 100% и помечает.
        config()->set('profit_funds.funds', [
            'dividends' => ['label' => 'Дивиденды', 'hint' => '', 'share' => 0.72],
            'reserve' => ['label' => 'Резерв', 'hint' => '', 'share' => 0.24],
            'team' => ['label' => 'Команда', 'hint' => '', 'share' => 0.12],
            'company' => ['label' => 'Компания', 'hint' => '', 'share' => 0.12],
        ]);
        $this->fakeReport(['2026-07' => ['ebitda' => 1_000_000, 'net' => 1_000_000]]);

        $snap = $this->app->make(ProfitFundsService::class)->snapshot();

        $this->assertNotNull($snap['share_note']);
        // 0.72/1.20 = 0.60 → 600k дивидендов после нормировки.
        $this->assertSame(600_000.0, $snap['current_allocations']['dividends']);
        // Сумма отчислений = вся прибыль (ничего не потеряно/не дорисовано).
        $this->assertEqualsWithDelta(
            1_000_000.0,
            array_sum($snap['current_allocations']),
            0.5,
        );
    }

    public function test_delegation_kpi_panel_aggregates_all_phases(): void
    {
        // Пустая БД (нет когорт/дебиторки) + прибыльный месяц через дублёр:
        // проверяем, что панель собирается и возвращает по карточке на фазу.
        $this->fakeReport(['2026-07' => ['ebitda' => 500_000, 'net' => 500_000]]);

        $snap = $this->app->make(DelegationKpiService::class)->snapshot();

        $keys = array_map(fn ($c) => $c['key'], $snap['cards']);
        $this->assertContains('unit_economics', $keys);   // A
        $this->assertContains('accrual_profit', $keys);    // B
        $this->assertContains('deferred_revenue', $keys);  // B
        $this->assertContains('receivables', $keys);       // C
        $this->assertContains('reserve_fund', $keys);      // D
        $this->assertContains('bdr', $keys);               // D (ожидает шаблона)

        // Прибыльный месяц с обеспеченной кассой → карточка B зелёная.
        $profitCard = collect($snap['cards'])->firstWhere('key', 'accrual_profit');
        $this->assertSame('success', $profitCard['level']);

        // БДР явно помечен серым «ожидает шаблона».
        $bdrCard = collect($snap['cards'])->firstWhere('key', 'bdr');
        $this->assertSame('gray', $bdrCard['level']);
    }
}
