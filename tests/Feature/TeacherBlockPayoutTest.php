<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherBlockPayoutTest extends TestCase
{
    use RefreshDatabase;

    private TeacherSalaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TeacherSalaryService::class);
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

    /** @test */
    public function block_payout_total_matches_the_spec_example(): void
    {
        // (48000 × 92%) × 30% + 3000 × 92% = 13248 + 2760 = 16008
        $this->assertSame(16008.0, TeacherSalaryService::blockPayoutTotal(48000, 92, 30, 3000));
    }

    /** @test */
    public function block_payout_total_adds_flat_surcharge_on_top(): void
    {
        // Доплата прибавляется к итогу как есть (без коэффициента и процента):
        // 16008 + 2000 = 18008.
        $this->assertSame(18008.0, TeacherSalaryService::blockPayoutTotal(48000, 92, 30, 3000, 2000));
    }

    /** @test */
    public function block_payout_total_subtracts_flat_deduction_from_the_total(): void
    {
        // Удержание вычитается из итога как есть (без коэффициента и процента):
        // 16008 − 1000 = 15008. Знак входного значения не важен.
        $this->assertSame(15008.0, TeacherSalaryService::blockPayoutTotal(48000, 92, 30, 3000, 0, 1000));
        $this->assertSame(15008.0, TeacherSalaryService::blockPayoutTotal(48000, 92, 30, 3000, 0, -1000));
    }

    /** @test */
    public function block_payout_total_runs_prior_block_payments_through_the_coefficient(): void
    {
        // Поздняя оплата идёт через коэффициент блока, как основная выручка:
        // (30000 × 92%) × 30% + 1800 × 92% = 8280 + 1656 = 9936.
        // (1800 = доля 6000 × процент своего курса 30%.)
        $this->assertSame(9936.0, TeacherSalaryService::blockPayoutTotal(30000, 92, 30, 0, 0, 0, 1800));

        // При коэффициенте 100% поведение прежнее — поздняя оплата как есть.
        $this->assertSame(2300.0, TeacherSalaryService::blockPayoutTotal(0, 100, 30, 0, 0, 0, 2300));
    }

    /** @test */
    public function block_group_revenue_sums_only_that_groups_real_payments_for_the_block(): void
    {
        $teacher = Teacher::create(['name' => 'Екатерина']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => 30]);
        for ($n = 1; $n <= 5; $n++) {
            CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'is_active' => true]);
        }

        $groupTue = Group::create(['name' => 'Хинди вторник']);
        $groupThu = Group::create(['name' => 'Хинди четверг']);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $u3 = User::factory()->create();
        $other = User::factory()->create();
        $groupTue->users()->attach([$u1->id, $u2->id, $u3->id]);
        $groupThu->users()->attach([$other->id]);

        // Группа «вторник»: два блочных платежа + один full (доля за блок = 30000/5 = 6000).
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 6000, 'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5]);
        $this->pay($course, ['user_id' => $u2->id, 'amount' => 6000, 'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5]);
        $this->pay($course, ['user_id' => $u3->id, 'amount' => 30000, 'tariff' => 'full']);

        // Шумы, которые НЕ должны попасть в базу:
        $this->pay($course, ['user_id' => $other->id, 'amount' => 6000, 'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5]); // другая группа
        $this->pay($course, ['user_id' => $u2->id, 'amount' => 6000, 'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5, 'is_conditional' => true]); // под обещание

        // Депозит u1 ТЕПЕРЬ входит: блоки пустые → разносится на все 5 блоков (5000/5 = 1000 на блок).
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 5000, 'tariff' => 'deposit']);

        // 6000 + 6000 + (30000/5) + (5000/5) = 19000
        $this->assertSame(19000.0, $this->service->blockGroupRevenue($course->id, 5, $groupTue->id));

        // По блоку 1 у вторника: доля full 30000/5 + доля депозита 5000/5 = 7000.
        $this->assertSame(7000.0, $this->service->blockGroupRevenue($course->id, 1, $groupTue->id));
    }

    /** @test */
    public function block_group_revenue_without_group_covers_all_course_students(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => 30]);
        for ($n = 1; $n <= 5; $n++) {
            CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'is_active' => true]);
        }

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 6000, 'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5]);
        $this->pay($course, ['user_id' => $u2->id, 'amount' => 6000, 'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5]);

        $this->assertSame(12000.0, $this->service->blockGroupRevenue($course->id, 5, null));
    }

    /** @test */
    public function block_revenue_subtracts_refund_expense_rows(): void
    {
        // Регрессия (money-core, H071 #5): blockGroupRevenueDetail игнорировал
        // возвраты (tariff='Расход', отрицательная сумма), т.к. фильтровал
        // whereNotIn(NON_REVENUE_TARIFFS) + amount>0. Полностью возвращённый блок
        // всё равно попадал в базу → преподавателю платили % с возвращённых денег.
        $course = Course::factory()->create(['salary_type' => 'percent', 'salary_value' => 30]);
        for ($n = 1; $n <= 3; $n++) {
            CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'is_active' => true]);
        }

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        // Два платежа за блок 2 по 10000; u1 полностью возвращён (Расход −10000).
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 10000, 'tariff' => 'block_2', 'start_block' => 2, 'end_block' => 2]);
        $this->pay($course, ['user_id' => $u2->id, 'amount' => 10000, 'tariff' => 'block_2', 'start_block' => 2, 'end_block' => 2]);
        $this->pay($course, ['user_id' => $u1->id, 'amount' => -10000, 'tariff' => 'Расход', 'start_block' => 2, 'end_block' => 2]);

        // База блока 2 = 20000 − 10000 = 10000, а не 20000.
        $detail = $this->service->blockGroupRevenueDetail($course->id, 2, null);
        $this->assertSame(10000.0, $detail['total']);

        // Возврат виден отдельной строкой с отрицательной долей.
        $returnLine = collect($detail['lines'])->firstWhere('is_return', true);
        $this->assertNotNull($returnLine);
        $this->assertSame(-10000.0, $returnLine['share']);
    }

    /** @test */
    public function block_revenue_floors_at_zero_when_refunds_exceed_revenue(): void
    {
        // Возврат больше выручки блока не уводит базу в минус (иначе отрицательная
        // выплата). База = max(0, нетто).
        $course = Course::factory()->create(['salary_type' => 'percent', 'salary_value' => 30]);
        for ($n = 1; $n <= 3; $n++) {
            CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'is_active' => true]);
        }

        $u1 = User::factory()->create();
        $this->pay($course, ['user_id' => $u1->id, 'amount' => 10000, 'tariff' => 'block_2', 'start_block' => 2, 'end_block' => 2]);
        $this->pay($course, ['user_id' => $u1->id, 'amount' => -12000, 'tariff' => 'Расход', 'start_block' => 2, 'end_block' => 2]);

        $this->assertSame(0.0, $this->service->blockGroupRevenue($course->id, 2, null));
    }

    /** @test */
    public function block_revenue_excludes_shares_already_paid_to_teacher(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => 30]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 2, 'is_active' => true]);

        $user = User::factory()->create();
        Payment::withoutEvents(function () use ($course, $user, &$payment) {
            $payment = Payment::create([
                'course_id' => $course->id,
                'user_id' => $user->id,
                'amount' => 10000,
                'tariff' => 'block_2',
                'start_block' => 2,
                'end_block' => 2,
                'status' => 'paid',
            ]);
        });

        $teacher->payouts()->create([
            'amount' => 3000,
            'paid_at' => now()->toDateString(),
            'breakdown' => [
                'course_id' => $course->id,
                'block_number' => 2,
                'payments' => [['payment_id' => $payment->id]],
            ],
        ]);

        $detail = $this->service->blockGroupRevenueDetail($course->id, 2, null, ['teacher_id' => $teacher->id]);

        $this->assertSame(0.0, $detail['total']);
        $this->assertSame([], $detail['lines']);
    }

    /** @test */
    public function prior_block_paid_share_is_excluded_from_later_block_base(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => 30]);
        foreach ([1, 2, 3] as $n) {
            CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'is_active' => true]);
        }

        $user = User::factory()->create();
        Payment::withoutEvents(function () use ($course, $user, &$payment) {
            $payment = Payment::create([
                'course_id' => $course->id,
                'user_id' => $user->id,
                'amount' => 30000,
                'tariff' => 'full',
                'status' => 'paid',
            ]);
        });

        $teacher->payouts()->create([
            'amount' => 3000,
            'paid_at' => now()->toDateString(),
            'breakdown' => [
                'course_id' => $course->id,
                'block_number' => 2,
                'prior_blocks_paid' => [[
                    'course_id' => $course->id,
                    'block_number' => 3,
                    'payment_id' => $payment->id,
                ]],
            ],
        ]);

        $detail = $this->service->blockGroupRevenueDetail($course->id, 3, null, ['teacher_id' => $teacher->id]);

        $this->assertSame(0.0, $detail['total']);
    }
}
