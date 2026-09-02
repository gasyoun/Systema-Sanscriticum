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

    // ---------------------------------------------------------------- H3951
    // Форма курса 266 с прода: блоки 1..5 стоят одним штампом массового
    // бэкофилла (FINDINGS §621), блоки 6..8 — настоящее расписание. Предоплата
    // августа 2026 покрывает блоки 1..4, то есть целиком лежит внутри штампа.

    private function stampedRunCourse(): array
    {
        [$teacher, $course] = $this->percentCourse(10);
        $this->blocks($course, [
            1 => '2025-03-14', 2 => '2025-03-14', 3 => '2025-03-14',
            4 => '2025-03-14', 5 => '2025-03-14',
            6 => '2026-09-08', 7 => '2026-10-06', 8 => '2026-11-03',
        ]);

        $u1 = User::factory()->create();
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => 144000,
            'tariff' => 'full',
            'start_block' => 1,
            'end_block' => 4,
            'created_at' => '2026-08-10 16:59:00',
        ]);

        return [$teacher, $course];
    }

    private function earnedInMonthFresh(Teacher $teacher, string $month): float
    {
        // Свежий сервис на каждый прогон: teacherCourseAccrualCache помнит
        // раскладку, и переключение флага на прогретом объекте сравнило бы два
        // одинаковых ответа из кэша.
        $start = Carbon::parse($month.'-01')->startOfMonth();

        return (new TeacherSalaryService)->totalForTeacher($teacher, $start, $start->copy()->endOfMonth());
    }

    /** @test */
    public function stamped_block_run_guard_is_off_by_default_and_changes_nothing(): void
    {
        $this->assertFalse(
            (bool) config('revenue.recognition_stamped_block_run_guard'),
            'Флаг сторожа обязан быть выключен по умолчанию.',
        );

        [$teacher] = $this->stampedRunCourse();

        // Поведение до H3951: вся предоплата признана в месяц штампа, на 17
        // месяцев назад, а в месяц прихода денег преподаватель видит ноль.
        $this->assertSame(14400.0, $this->earnedInMonthFresh($teacher, '2025-03'));
        $this->assertSame(0.0, $this->earnedInMonthFresh($teacher, '2026-08'));
    }

    /** @test */
    public function stamped_block_run_guard_moves_the_prepayment_to_the_payment_month(): void
    {
        [$teacher] = $this->stampedRunCourse();

        config(['revenue.recognition_stamped_block_run_guard' => true]);

        $this->assertSame(14400.0, $this->earnedInMonthFresh($teacher, '2026-08'));
        $this->assertSame(0.0, $this->earnedInMonthFresh($teacher, '2025-03'));
        // Сумма за всё время не меняется — сторож переносит признание, а не
        // создаёт и не уничтожает деньги.
        $this->assertSame(14400.0, (new TeacherSalaryService)->totalForTeacher($teacher));
    }

    /** @test */
    public function stamped_block_run_guard_leaves_a_real_schedule_alone(): void
    {
        [$teacher, $course] = $this->percentCourse(10);
        $this->blocks($course, [1 => '2026-01-10', 2 => '2026-02-10', 3 => '2026-03-10']);

        $u1 = User::factory()->create();
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => 9000,
            'tariff' => 'full',
            'created_at' => '2025-12-15 10:00:00',
        ]);

        config(['revenue.recognition_stamped_block_run_guard' => true]);

        // Настоящее расписание раскладывается по месяцам блоков как и раньше.
        $this->assertSame(300.0, $this->earnedInMonthFresh($teacher, '2026-01'));
        $this->assertSame(300.0, $this->earnedInMonthFresh($teacher, '2026-02'));
        $this->assertSame(300.0, $this->earnedInMonthFresh($teacher, '2026-03'));
        $this->assertSame(0.0, $this->earnedInMonthFresh($teacher, '2025-12'));
    }

    /** @test */
    public function manual_override_still_wins_over_the_stamped_run_guard(): void
    {
        [$teacher, $course] = $this->percentCourse(10);
        $this->blocks($course, [
            1 => '2025-03-14', 2 => '2025-03-14', 3 => '2025-03-14', 4 => '2025-03-14',
        ]);

        $u1 = User::factory()->create();
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => 144000,
            'tariff' => 'full',
            'start_block' => 1,
            'end_block' => 4,
            'created_at' => '2026-08-10 16:59:00',
            'salary_recognition_month' => '2026-10',
        ]);

        config(['revenue.recognition_stamped_block_run_guard' => true]);

        $this->assertSame(14400.0, $this->earnedInMonthFresh($teacher, '2026-10'));
        $this->assertSame(0.0, $this->earnedInMonthFresh($teacher, '2026-08'));
        $this->assertSame(0.0, $this->earnedInMonthFresh($teacher, '2025-03'));
    }
}
