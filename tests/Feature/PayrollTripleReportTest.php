<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H5554 gap [1]: тройной отчёт по циклам выплат. Сборочный слой над
 * FinanceCockpitReport: per-cycle ДДС + ОПиУ (начисление) + снимок Баланса.
 * Фикстуры — только Expense (детерминированная арифметика, как в
 * FinanceCockpitOpexTest); выручка/ЗП/маркетинг держатся нулевыми.
 */
class PayrollTripleReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedThisMonthOpex(): void
    {
        $now = now();
        Expense::create(['spent_at' => $now, 'category' => ExpenseCategory::Acquiring, 'amount' => 5000]);
        Expense::create(['spent_at' => $now, 'category' => ExpenseCategory::Hosting, 'amount' => 3000]);
        // Прошлый месяц — не должен попасть в текущий цикл.
        Expense::create([
            'spent_at' => $now->copy()->subMonthNoOverflow()->startOfMonth(),
            'category' => ExpenseCategory::Hosting,
            'amount' => 99999,
        ]);
    }

    /** @test */
    public function json_output_wires_per_cycle_dds_opiu_and_balance_snapshot(): void
    {
        $this->seedThisMonthOpex();

        Artisan::call('payroll:triple-report', [
            '--periods' => now()->format('Y-m'),
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('payroll-triple-report/v1', $payload['schema']);
        $this->assertCount(1, $payload['cycles']);

        $cycle = $payload['cycles'][0];
        $this->assertSame(now()->format('Y-m'), $cycle['period']);

        // ДДС: inflow 0 (нет платежей), opexOut 8000, net −8000. Прошлый месяц исключён.
        $this->assertSame(0.0, (float) $cycle['dds']['inflow']);
        $this->assertSame(8000.0, (float) $cycle['dds']['opexOut']);
        $this->assertSame(-8000.0, (float) $cycle['dds']['net']);

        // ОПиУ (начисление): выручка 0, эквайринг 5000 — переменные, 3000 — админ.
        $this->assertSame(0.0, (float) $cycle['opiu_accrual']['revenue']);
        $this->assertSame(5000.0, (float) $cycle['opiu_accrual']['acquiring']);
        // валовая = −5000 (эквайринг), EBITDA = валовая − маркетинг 0 − админ 3000.
        $this->assertSame(-8000.0, (float) $cycle['opiu_accrual']['ebitda']);

        // Фонд ЗП (MG 01-10: 12% поступлений цикла) — при нулевой кассе 0.
        $this->assertSame(0.12, (float) $cycle['salary_fund']['rate']);
        $this->assertSame(0.0, (float) $cycle['salary_fund']['amount']);

        // Отложенная выручка присутствует и сведена: cash − recognized = deferred.
        $this->assertEqualsWithDelta(
            $cycle['deferred_revenue']['cashReceived'] - $cycle['deferred_revenue']['recognized'],
            $cycle['deferred_revenue']['deferred'],
            0.01,
        );

        // Баланс — один снимок с обязательствами и авансами.
        $this->assertArrayHasKey('teacherPayable', $payload['balance_snapshot']);
        $this->assertArrayHasKey('advances', $payload['balance_snapshot']);
        $this->assertSame(0.0, (float) $payload['balance_snapshot']['advances']);
    }

    /** @test */
    public function text_output_renders_one_section_per_cycle(): void
    {
        $this->seedThisMonthOpex();

        $previous = now()->format('Y-m');
        $current = now()->copy()->addMonthNoOverflow()->format('Y-m');
        Artisan::call('payroll:triple-report', ['--periods' => $previous.','.$current]);
        $out = Artisan::output();

        $this->assertSame(2, substr_count($out, ' DDS (kassa) ==='));
        $this->assertSame(2, substr_count($out, ' OPiU (accrual) ==='));
        $this->assertStringContainsString('=== Balance snapshot (as of now) ===', $out);
        $this->assertStringContainsString('outstanding_advances', $out);
        $this->assertStringContainsString('remaining_obligation', $out);
    }

    /** @test */
    public function bad_period_and_format_fail_closed(): void
    {
        $this->artisan('payroll:triple-report', ['--periods' => '2026-13'])->assertExitCode(1);
        $this->artisan('payroll:triple-report', ['--periods' => 'not-a-period'])->assertExitCode(1);
        $this->artisan('payroll:triple-report', [
            '--periods' => now()->format('Y-m'),
            '--format' => 'yaml',
        ])->assertExitCode(1);
    }
}
