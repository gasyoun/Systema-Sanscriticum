<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherSalary;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Расход» — общий журнал: выплаты самим преподавателям, реклама, налоги лежат
 * тем же тарифом, что и возвраты студентам. Прод 29-09-2026: на курсе 348 выплаты
 * самому преподавателю ÷12 резали базу блока.
 * С флагом salary_returns_student_refunds_only из базы вычитаются только
 * настоящие возвраты студентам. Флаг OFF — поведение прежнее.
 */
class SalaryReturnsStudentRefundsOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Teacher $teacher;

    private Course $course;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = Teacher::create(['name' => 'Преподаватель А']);
        $this->course = Course::factory()->create([
            'teacher_id' => $this->teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        foreach ([1, 2, 3, 4] as $n) {
            CourseBlock::factory()->for($this->course)->create(['number' => $n]);
        }

        $this->buyer = User::factory()->create();
        // Выручка блока 2: 12 000 ₽ от покупателя.
        $this->pay(['user_id' => $this->buyer->id, 'amount' => 12000, 'tariff' => 'block_2', 'start_block' => 2, 'end_block' => 2]);
    }

    /** @param array<string, mixed> $attrs */
    private function pay(array $attrs): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create(array_merge([
            'course_id' => $this->course->id,
            'status' => 'paid',
            'is_conditional' => false,
        ], $attrs)));
    }

    /** Выплата самому преподавателю, записанная «Расходом» без блоков (как на проде). */
    private function teacherPayoutAsExpense(float $amount = -8000): Payment
    {
        $system = User::factory()->create(['name' => 'Системные расходы']);

        return $this->pay([
            'user_id' => $system->id,
            'amount' => $amount,
            'tariff' => 'Расход',
            'transaction_id' => 'Преподаватель А - курс',
        ]);
    }

    private function blockRevenue(): float
    {
        return app(TeacherSalaryService::class)->blockGroupRevenue($this->course->id, 2, null);
    }

    public function test_flag_off_keeps_the_old_behaviour(): void
    {
        config(['features.salary_returns_student_refunds_only' => false]);
        $this->teacherPayoutAsExpense(-8000);

        // −8000 ÷ 4 блока = −2000 с блока 2 — прежняя (ошибочная) логика не тронута.
        $this->assertSame(10000.0, $this->blockRevenue());
    }

    public function test_teacher_payout_ads_and_taxes_do_not_cut_the_block_base(): void
    {
        config(['features.salary_returns_student_refunds_only' => true]);
        $this->teacherPayoutAsExpense(-8000);
        $this->pay(['user_id' => User::factory()->create()->id, 'amount' => -4000, 'tariff' => 'Расход', 'transaction_id' => 'Реклама - Реклама']);

        $this->assertSame(12000.0, $this->blockRevenue());

        $detail = app(TeacherSalaryService::class)->blockGroupRevenueDetail($this->course->id, 2, null);
        $this->assertCount(0, array_filter($detail['lines'], fn (array $l): bool => $l['is_return']), 'Калькулятор блока не показывает выплаты как возвраты.');
    }

    public function test_refund_to_a_buyer_without_blocks_is_still_subtracted(): void
    {
        config(['features.salary_returns_student_refunds_only' => true]);
        $this->pay(['user_id' => $this->buyer->id, 'amount' => -4000, 'tariff' => 'Расход']);

        // −4000 ÷ 4 блока = −1000 с блока 2.
        $this->assertSame(11000.0, $this->blockRevenue());
    }

    public function test_refund_linked_to_the_original_payment_is_subtracted(): void
    {
        config(['features.salary_returns_student_refunds_only' => true]);
        $original = Payment::query()->where('user_id', $this->buyer->id)->firstOrFail();
        $stranger = User::factory()->create();
        $this->pay(['user_id' => $stranger->id, 'amount' => -4000, 'tariff' => 'Расход', 'refund_of_payment_id' => $original->id]);

        $this->assertSame(11000.0, $this->blockRevenue());
    }

    public function test_refund_with_blocks_hits_only_its_own_blocks(): void
    {
        config(['features.salary_returns_student_refunds_only' => true]);
        $this->pay(['user_id' => User::factory()->create()->id, 'amount' => -3000, 'tariff' => 'Расход', 'start_block' => 2, 'end_block' => 2]);

        $this->assertSame(9000.0, $this->blockRevenue());
        $this->assertSame(0.0, app(TeacherSalaryService::class)->blockGroupRevenue($this->course->id, 3, null));
    }

    public function test_report_shows_the_difference_and_writes_nothing(): void
    {
        config(['features.salary_returns_student_refunds_only' => false]);
        $payout = $this->teacherPayoutAsExpense(-8000);
        $payments = Payment::query()->count();

        $this->artisan('salary:refund-filter-report', ['--teacher' => (string) $this->teacher->id, '--rows' => true])
            // Каждое ожидание «съедает» свою строку вывода — проверяем разные строки.
            ->expectsOutputToContain('− #'.$payout->id)
            ->expectsOutputToContain('#'.$this->teacher->id.' Преподаватель А')
            ->expectsOutputToContain('2 000,00 ₽')
            ->assertSuccessful();

        $this->assertSame($payments, Payment::query()->count());
        $this->assertFalse((bool) config('features.salary_returns_student_refunds_only'), 'Флаг в памяти возвращён как был.');
    }

    public function test_monthly_accrual_and_tooltip_use_the_same_rule(): void
    {
        $this->teacherPayoutAsExpense(-8000);

        config(['features.salary_returns_student_refunds_only' => false]);
        $off = app(TeacherSalaryService::class);
        $this->assertNotEmpty($off->returnsForTeacher($this->teacher), 'Флаг OFF: выплата видна как возврат.');
        $offTotals = $off->periodTotals($this->teacher);

        config(['features.salary_returns_student_refunds_only' => true]);
        $on = app(TeacherSalaryService::class);
        $this->assertSame([], $on->returnsForTeacher($this->teacher), 'Флаг ON: выплата преподавателю — не возврат.');
        $onTotals = $on->periodTotals($this->teacher);

        $this->assertSame(0.0, (float) $onTotals['returns']);
        $this->assertGreaterThan((float) $offTotals['net'], (float) $onTotals['net']);
        $this->assertSame((float) $offTotals['gross'], (float) $onTotals['gross'], 'Валовая часть не меняется.');
    }
}
