<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Support\FinanceCockpitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Регресс на месячную границу окон финансов (issue #935, H1996): сравнение
 * `whereBetween(col, [toDateString(), toDateString()])` теряло записи ПОСЛЕДНЕГО
 * дня окна, у которых значение хранится со временем (SQLite пишет 'Y-m-d H:i:s'
 * даже под cast 'date'), потому что '2026-07-31 18:00:00' строково больше
 * '2026-07-31'. До фикса весь FinanceCockpitOpexTest падал каждое последнее
 * число месяца и был зелёным в остальные дни. Время здесь ЗАМОРОЖЕНО на вечере
 * последнего дня месяца, чтобы регресс воспроизводился в любой день прогона.
 */
class FinanceMonthEndWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-31 18:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function expense_spent_on_last_day_of_month_stays_in_window(): void
    {
        Expense::create(['spent_at' => now(), 'category' => ExpenseCategory::Hosting, 'amount' => 3000]);

        [$start, $end] = app(FinanceCockpitReport::class)->monthBounds(now()->format('Y-m'));

        $this->assertSame(3000.0, Expense::totalForWindow($start, $end));
        $this->assertSame(3000.0, Expense::byCategoryForWindow($start, $end)[ExpenseCategory::Hosting->value]);
    }

    /** @test */
    public function payout_paid_on_last_day_of_month_stays_in_dds_outflow(): void
    {
        $teacher = Teacher::factory()->create();
        TeacherPayout::create([
            'teacher_id' => $teacher->id,
            'amount' => 7000,
            'paid_at' => now(),
        ]);

        $dds = app(FinanceCockpitReport::class)->dds(now()->format('Y-m'));

        $this->assertSame(7000.0, $dds['salaryOut']);
    }
}
