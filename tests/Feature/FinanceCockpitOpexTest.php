<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Support\FinanceCockpitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Проверяет недостающую расходную сторону ОПиУ (H207): агрегацию opex по модели
 * Expense и её проводку в блоки «Нескучных финансов» (переменные/административные)
 * плюс отток opex в ДДС. Ревенью/ЗП/маркетинг тестируются отдельно и здесь
 * держатся нулевыми, чтобы арифметика была детерминированной.
 */
class FinanceCockpitOpexTest extends TestCase
{
    use RefreshDatabase;

    private function seedThisMonthOpex(): void
    {
        $now = now();
        Expense::create(['spent_at' => $now, 'category' => ExpenseCategory::Acquiring, 'amount' => 5000]);
        Expense::create(['spent_at' => $now, 'category' => ExpenseCategory::Hosting, 'amount' => 3000]);
        Expense::create(['spent_at' => $now, 'category' => ExpenseCategory::Contractors, 'amount' => 2000]);
        Expense::create(['spent_at' => $now, 'category' => ExpenseCategory::TaxesBankOther, 'amount' => 1000]);
        // Прошлый месяц — не должен попасть в текущее окно.
        Expense::create([
            'spent_at' => $now->copy()->subMonthNoOverflow()->startOfMonth(),
            'category' => ExpenseCategory::Hosting,
            'amount' => 99999,
        ]);
    }

    /** @test */
    public function expense_aggregation_respects_window_and_category(): void
    {
        $this->seedThisMonthOpex();

        [$start, $end] = app(FinanceCockpitReport::class)->monthBounds(now()->format('Y-m'));

        $byCat = Expense::byCategoryForWindow($start, $end);
        $this->assertSame(5000.0, $byCat[ExpenseCategory::Acquiring->value]);
        $this->assertSame(3000.0, $byCat[ExpenseCategory::Hosting->value]); // прошлый месяц исключён
        $this->assertSame(2000.0, $byCat[ExpenseCategory::Contractors->value]);
        $this->assertSame(1000.0, $byCat[ExpenseCategory::TaxesBankOther->value]);

        $this->assertSame(11000.0, Expense::totalForWindow($start, $end));
        $this->assertSame(5000.0, Expense::totalForWindow($start, $end, ExpenseCategory::Acquiring));
    }

    /** @test */
    public function opiu_wires_opex_into_nf_blocks(): void
    {
        $this->seedThisMonthOpex();

        $opiu = app(FinanceCockpitReport::class)->opiu(now()->format('Y-m'));

        // Эквайринг — переменный расход; остальные три — административные.
        $this->assertSame(5000.0, $opiu['acquiring']);
        $this->assertSame(5000.0, $opiu['variableTotal']); // ЗП-COGS = 0 в этом тесте
        $this->assertSame(6000.0, $opiu['adminTotal']);    // 3000 + 2000 + 1000
        $this->assertSame(11000.0, $opiu['opexTotal']);

        // Ревенью 0 → валовая = −переменные, EBITDA = валовая − коммерческие − админ.
        $this->assertSame(0.0, $opiu['revenue']);
        $this->assertSame(-5000.0, $opiu['grossProfit']);
        $this->assertSame(-11000.0, $opiu['ebitda']);
        $this->assertNull($opiu['ebitdaMargin']); // деление на нулевую выручку
    }

    /** @test */
    public function dds_includes_opex_outflow(): void
    {
        $this->seedThisMonthOpex();

        $dds = app(FinanceCockpitReport::class)->dds(now()->format('Y-m'));

        $this->assertSame(11000.0, $dds['opexOut']);
        $this->assertSame(0.0, $dds['inflow']);
        $this->assertSame(-11000.0, $dds['net']);
    }
}
