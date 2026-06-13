<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Признание ЗП по периоду блока (accrual): предоплата раскидывается вперёд,
 * просрочка падает в месяц оплаченного блока, override переносит сумму.
 */
class TeacherSalaryAccrualTest extends TestCase
{
    use RefreshDatabase;

    private TeacherSalaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TeacherSalaryService::class);
    }

    private function percentCourse(float $pct = 10): array
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => $pct,
        ]);

        return [$teacher->fresh('courses'), $course];
    }

    /**
     * @param  array<int, string>  $blocks  [номер => дата старта 'Y-m-d']
     */
    private function blocks(Course $course, array $blocks): void
    {
        foreach ($blocks as $number => $startsAt) {
            CourseBlock::create([
                'course_id' => $course->id,
                'number' => $number,
                'starts_at' => $startsAt,
                'ends_at' => Carbon::parse($startsAt)->endOfMonth(),
                'is_active' => true,
            ]);
        }
    }

    private function pay(Course $course, array $attrs): void
    {
        Payment::withoutEvents(function () use ($course, $attrs) {
            Payment::create(array_merge([
                'course_id' => $course->id,
                'status' => 'paid',
                'is_conditional' => false,
            ], $attrs));
        });
    }

    private function earnedInMonth(Teacher $teacher, string $month): float
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();

        return $this->service->totalForTeacher($teacher, $start, $start->copy()->endOfMonth());
    }

    /** @test */
    public function prepaid_full_payment_is_spread_forward_across_block_months(): void
    {
        [$teacher, $course] = $this->percentCourse(10);
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10']);

        $u1 = User::factory()->create();
        // Предоплата за весь курс в декабре 2025 — до старта блоков.
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => 9000,
            'tariff' => 'full',
            'created_at' => '2025-12-15 10:00:00',
        ]);

        // 9000 / 3 блока = 3000 на блок; 10% = 300 в каждый месяц блока.
        $this->assertSame(300.0, $this->earnedInMonth($teacher, '2026-01'));
        $this->assertSame(300.0, $this->earnedInMonth($teacher, '2026-02'));
        $this->assertSame(300.0, $this->earnedInMonth($teacher, '2026-03'));
        // В месяц поступления денег (декабрь 2025) ничего не признаётся.
        $this->assertSame(0.0, $this->earnedInMonth($teacher, '2025-12'));
        // За всё время — полная сумма.
        $this->assertSame(900.0, $this->service->totalForTeacher($teacher));
    }

    /** @test */
    public function late_block_payment_is_recognized_in_the_block_month_not_payment_month(): void
    {
        [$teacher, $course] = $this->percentCourse(10);
        $this->blocks($course, [
            1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10',
            4 => '2026-04-10', 5 => '2026-05-10',
        ]);

        $u1 = User::factory()->create();
        // Оплата блока 5 пришла с опозданием — в июле, хотя блок шёл в мае.
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => 2000,
            'tariff' => 'block_5',
            'start_block' => 5,
            'end_block' => 5,
            'created_at' => '2026-07-20 10:00:00',
        ]);

        $this->assertSame(200.0, $this->earnedInMonth($teacher, '2026-05'));
        $this->assertSame(0.0, $this->earnedInMonth($teacher, '2026-07'));
    }

    /** @test */
    public function manual_override_month_takes_precedence_over_block_split(): void
    {
        [$teacher, $course] = $this->percentCourse(10);
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10']);

        $u1 = User::factory()->create();
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => 9000,
            'tariff' => 'full',
            'created_at' => '2025-12-15 10:00:00',
            'salary_recognition_month' => '2026-09',
        ]);

        // Вся сумма признаётся в сентябре 2026, авто-раскладка по блокам игнорируется.
        $this->assertSame(900.0, $this->earnedInMonth($teacher, '2026-09'));
        $this->assertSame(0.0, $this->earnedInMonth($teacher, '2026-01'));
        $this->assertSame(900.0, $this->service->totalForTeacher($teacher));
    }

    /** @test */
    public function returns_reduce_the_recognized_revenue_in_their_month(): void
    {
        [$teacher, $course] = $this->percentCourse(10);
        $this->blocks($course, [1 => '2026-01-10']);

        $u1 = User::factory()->create();
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => 1000,
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'created_at' => '2026-01-12 10:00:00',
        ]);
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => -400,
            'tariff' => 'Расход',
            'created_at' => '2026-01-20 10:00:00',
        ]);

        // (1000 − 400) × 10% = 60.
        $this->assertSame(60.0, $this->earnedInMonth($teacher, '2026-01'));
        $this->assertSame(60.0, $this->service->totalForTeacher($teacher));
    }

    /** @test */
    public function period_totals_split_gross_and_returns(): void
    {
        [$teacher, $course] = $this->percentCourse(10);
        $this->blocks($course, [1 => '2026-01-10']);

        $u1 = User::factory()->create();
        $this->pay($course, [
            'user_id' => $u1->id, 'amount' => 1000, 'tariff' => 'block_1',
            'start_block' => 1, 'end_block' => 1, 'created_at' => '2026-01-12 10:00:00',
        ]);
        $this->pay($course, [
            'user_id' => $u1->id, 'amount' => -400, 'tariff' => 'Расход',
            'created_at' => '2026-01-20 10:00:00',
        ]);

        $start = Carbon::parse('2026-01-01')->startOfMonth();
        $totals = $this->service->periodTotals($teacher, $start, $start->copy()->endOfMonth());

        // Валовое (только положительное) и эффект возврата — отдельно; чистое = сумма.
        $this->assertSame(100.0, $totals['gross']);
        $this->assertSame(-40.0, $totals['returns']);
        $this->assertSame(60.0, $totals['net']);
    }
}
