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
}
