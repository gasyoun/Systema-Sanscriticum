<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeacherSalaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeacherSalaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TeacherSalaryService::class);
    }

    private function teacherWithCourse(string $salaryType, float $salaryValue): array
    {
        $teacher = Teacher::create(['name' => 'Препод '.$salaryType]);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => $salaryType,
            'salary_value' => $salaryValue,
        ]);

        return [$teacher->fresh('courses'), $course];
    }

    /**
     * Создаёт платёж БЕЗ запуска обсёрвера (нам нужны только строки в БД,
     * без выдачи доступа/праны/Telegram).
     */
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

    /** @test */
    public function percent_includes_deposit_trial_and_subtracts_returns(): void
    {
        [$teacher, $course] = $this->teacherWithCourse('percent', 10);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        $this->pay($course, ['user_id' => $u1->id, 'amount' => 12000, 'tariff' => 'full']);
        $this->pay($course, ['user_id' => $u2->id, 'amount' => 4800, 'tariff' => 'block_1']);
        // A1: депозит (бронь) и пробное ТЕПЕРЬ входят в базу — реальные деньги курса.
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 5000, 'tariff' => 'deposit']);
        $this->pay($course, ['user_id' => $u2->id, 'amount' => 1000, 'tariff' => 'trial']);
        // A2: возврат уменьшает базу.
        $this->pay($course, ['user_id' => $u1->id, 'amount' => -2000, 'tariff' => 'Расход']);

        $break = $this->service->breakdownForTeacher($teacher);
        $row = $break['rows'][0];

        // revenue — положительная выручка (без возвратов): 12000 + 4800 + 5000 + 1000.
        $this->assertSame(22800.0, $row['revenue']);
        $this->assertSame(-2000.0, $row['returns']);
        // Итог чистый: (22800 − 2000) * 10% = 2080
        $this->assertSame(2080.0, $break['total']);
    }

    /** @test */
    public function fix_per_student_counts_unique_students_including_deposits(): void
    {
        [$teacher, $course] = $this->teacherWithCourse('fix_per_student', 1000);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();

        $this->pay($course, ['user_id' => $u1->id, 'amount' => 4800, 'tariff' => 'block_1']);
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 4800, 'tariff' => 'block_2']); // тот же студент
        $this->pay($course, ['user_id' => $u2->id, 'amount' => 12000, 'tariff' => 'full']);
        // Депозит (бронь) третьего студента теперь делает его реальным студентом курса.
        $this->pay($course, ['user_id' => $u3->id, 'amount' => 5000, 'tariff' => 'deposit']);

        $break = $this->service->breakdownForTeacher($teacher);

        $this->assertSame(3, $break['rows'][0]['students']);
        $this->assertSame(3000.0, $break['total']);
    }

    /** @test */
    public function fix_total_pays_once_when_there_are_real_payments(): void
    {
        [$teacher, $course] = $this->teacherWithCourse('fix_total', 5000);

        $u1 = User::factory()->create();
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 12000, 'tariff' => 'full']);

        $this->assertSame(5000.0, $this->service->breakdownForTeacher($teacher)['total']);
    }

    /** @test */
    public function fix_total_pays_for_deposit_only_now_that_it_is_revenue(): void
    {
        [$teacher, $course] = $this->teacherWithCourse('fix_total', 5000);

        $u1 = User::factory()->create();
        // Депозит (бронь) теперь считается реальной выручкой курса → фикс начисляется.
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 5000, 'tariff' => 'deposit']);

        $this->assertSame(5000.0, $this->service->breakdownForTeacher($teacher)['total']);
    }

    /** @test */
    public function fix_total_pays_nothing_without_any_revenue(): void
    {
        [$teacher, $course] = $this->teacherWithCourse('fix_total', 5000);

        $u1 = User::factory()->create();
        // Только возврат — положительной выручки нет.
        $this->pay($course, ['user_id' => $u1->id, 'amount' => -2000, 'tariff' => 'Расход']);

        $this->assertSame(0.0, $this->service->breakdownForTeacher($teacher)['total']);
    }

    /** @test */
    public function percent_per_block_converges_with_percent_under_accrual(): void
    {
        // Под accrual full тоже раскладывается по блокам, поэтому percent_per_block
        // считает всю выручку (исправление прежнего недосчёта full) и за всё время
        // совпадает с percent: (12000 + 4800) * 10% = 1680.
        [$teacher, $course] = $this->teacherWithCourse('percent_per_block', 10);
        Tariff::factory()->block(1)->create(['course_id' => $course->id]);
        Tariff::factory()->block(2)->create(['course_id' => $course->id]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        $this->pay($course, ['user_id' => $u1->id, 'amount' => 12000, 'tariff' => 'full']);
        $this->pay($course, ['user_id' => $u2->id, 'amount' => 4800, 'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1]);

        $this->assertSame(1680.0, $this->service->breakdownForTeacher($teacher)['total']);
    }

    /** @test */
    public function fix_per_block_multiplies_rate_by_block_count(): void
    {
        [$teacher, $course] = $this->teacherWithCourse('fix_per_block', 500);
        Tariff::factory()->block(1)->create(['course_id' => $course->id]);
        Tariff::factory()->block(2)->create(['course_id' => $course->id]);
        Tariff::factory()->block(3)->create(['course_id' => $course->id]);

        $u1 = User::factory()->create();
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 12000, 'tariff' => 'full']);

        // 3 блока × 500 = 1500
        $this->assertSame(1500.0, $this->service->breakdownForTeacher($teacher)['total']);
    }

    /** @test */
    public function technical_course_is_excluded(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'slug' => 'system-expenses',
            'salary_type' => 'percent',
            'salary_value' => 50,
        ]);
        $u1 = User::factory()->create();
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 10000, 'tariff' => 'full']);

        $this->assertSame(0.0, $this->service->breakdownForTeacher($teacher->fresh('courses'))['total']);
    }

    /** @test */
    public function gross_flag_uses_price_before_discount(): void
    {
        [$teacher, $course] = $this->teacherWithCourse('percent', 10);
        $u1 = User::factory()->create();
        // Уплачено 9000 при скидке 10% → брутто 10000.
        $this->pay($course, [
            'user_id' => $u1->id,
            'amount' => 9000,
            'tariff' => 'full',
            'discount_percent' => 10,
        ]);

        $net = $this->service->breakdownForTeacher($teacher)['total'];
        $gross = $this->service->breakdownForTeacher($teacher, null, null, ['gross_before_discount' => true])['total'];

        $this->assertSame(900.0, $net);   // 9000 * 10%
        $this->assertSame(1000.0, $gross); // 10000 * 10%
    }

    /** @test */
    public function summary_period_and_balance_are_correct(): void
    {
        [$teacher, $course] = $this->teacherWithCourse('fix_total', 5000);
        $u1 = User::factory()->create();

        // Оплата в текущем месяце.
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 12000, 'tariff' => 'full']);

        $period = now()->format('Y-m');

        // Выплата за этот период.
        $teacher->payouts()->create([
            'amount' => 2000,
            'paid_at' => now()->toDateString(),
            'period_month' => $period,
        ]);

        $summary = $this->service->summaryForAll($period);
        $row = $summary[$teacher->id];

        $this->assertSame(5000.0, $row['earned_period']);
        $this->assertSame(5000.0, $row['earned_all_time']);
        $this->assertSame(2000.0, $row['paid_period']);
        $this->assertSame(2000.0, $row['paid_all_time']);
        $this->assertSame(3000.0, $row['balance']);
    }

    /** @test */
    public function payout_paid_at_falls_into_period_without_period_month(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);

        // Легаси-выплата без period_month, но с paid_at в текущем месяце.
        $teacher->payouts()->create([
            'amount' => 1500,
            'paid_at' => now()->startOfMonth()->toDateString(),
        ]);

        $summary = $this->service->summaryForAll(now()->format('Y-m'));

        $this->assertSame(1500.0, $summary[$teacher->id]['paid_period']);
    }

    /** @test */
    public function summary_reports_days_since_last_payout_for_recent_payment(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $teacher->payouts()->create([
            'amount' => 1000,
            'paid_at' => now()->subDays(5)->toDateString(),
        ]);

        $summary = $this->service->summaryForAll(now()->format('Y-m'));
        $row = $summary[$teacher->id];

        $this->assertSame(5, $row['days_since_last_payout']);
        $this->assertIsInt($row['days_since_last_payout']);
        $this->assertSame(now()->subDays(5)->toDateString(), $row['last_paid_at']);
    }

    /** @test */
    public function summary_reports_null_days_since_last_payout_without_any_payout(): void
    {
        $teacher = Teacher::create(['name' => 'Без выплат']);

        $summary = $this->service->summaryForAll(now()->format('Y-m'));
        $row = $summary[$teacher->id];

        $this->assertNull($row['days_since_last_payout']);
        $this->assertNull($row['last_paid_at']);
    }

    /** @test */
    public function summary_days_since_last_payout_uses_the_most_recent_of_regular_and_advance_payouts(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        // Старая обычная выплата.
        $teacher->payouts()->create([
            'amount' => 1000,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => now()->subDays(20)->toDateString(),
        ]);
        // Более свежий аванс — должен «победить» в MAX(paid_at) (правило
        // handoff-а: считаем по выплатам любого типа, не только regular).
        $teacher->payouts()->create([
            'amount' => 500,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => now()->subDays(3)->toDateString(),
        ]);

        $summary = $this->service->summaryForAll(now()->format('Y-m'));
        $row = $summary[$teacher->id];

        $this->assertSame(3, $row['days_since_last_payout']);
    }

    /** @test */
    public function summary_days_since_last_payout_counts_correctly_across_a_month_boundary(): void
    {
        // Регресс на границу дат (issue #935/H1996: toDateString() терял
        // выплаты последнего дня окна). Здесь MAX(paid_at) не оконный, но
        // выплата в последний календарный день месяца не должна «съезжать»
        // на день из-за отсечения времени/часового пояса при парсинге.
        Carbon::setTestNow(Carbon::create(2026, 2, 3, 10, 0, 0));

        $teacher = Teacher::create(['name' => 'Препод']);
        $teacher->payouts()->create([
            'amount' => 1000,
            'paid_at' => '2026-01-31',
        ]);

        $summary = $this->service->summaryForAll(now()->format('Y-m'));
        $row = $summary[$teacher->id];

        $this->assertSame('2026-01-31', $row['last_paid_at']);
        $this->assertSame(3, $row['days_since_last_payout']);

        Carbon::setTestNow();
    }
}
