<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\PayrollContourService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollContourServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: array<int, Payment>} */
    private function makeStaffUser(string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $payments = [];
        foreach ([3, 2, 1] as $monthsAgo) {
            $p = Payment::create([
                'user_id' => $user->id,
                'amount' => -30000,
                'tariff' => 'Расход',
                'status' => 'paid',
            ]);
            $p->forceFill(['created_at' => now()->subMonths($monthsAgo)->startOfMonth()->addDays(4)])
                ->saveQuietly();
            $payments[] = $p;
        }

        return [$user, $payments];
    }

    /** @test */
    public function staff_payees_excludes_teachers_and_estimates_recurring_debt(): void
    {
        // Связанный с преподавателем получатель — исключён из списка персонала.
        $teacher = Teacher::factory()->create(['name' => 'Fixture Teach']);
        $teacherUser = User::factory()->create(['name' => 'Fixture Teach User', 'teacher_id' => $teacher->id]);
        $tp = Payment::create([
            'user_id' => $teacherUser->id,
            'amount' => -50000,
            'tariff' => 'Расход',
            'status' => 'paid',
        ]);
        $tp->forceFill(['created_at' => now()->subMonth()])->saveQuietly();

        [$staff, $staffPayments] = $this->makeStaffUser('Куратор Фикстура');

        // Разовый получатель — без ставки и оценки.
        $oneoff = User::factory()->create(['name' => 'Разовый Фикстура']);
        $op = Payment::create([
            'user_id' => $oneoff->id,
            'amount' => -15000,
            'tariff' => 'Расход',
            'status' => 'paid',
        ]);
        $op->forceFill(['created_at' => now()->subDays(9)])->saveQuietly();

        // Возврат студенту (refund_of_payment_id задан) — не получатель.
        $student = User::factory()->create(['name' => 'Студент Фикстура']);
        $refund = Payment::create([
            'user_id' => $student->id,
            'amount' => -6000,
            'tariff' => 'Расход',
            'status' => 'paid',
            'refund_of_payment_id' => $staffPayments[0]->id,
        ]);
        $refund->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $out = app(PayrollContourService::class)->staffPayees();

        $byUser = collect($out['payees'])->keyBy('user_id');
        $this->assertArrayNotHasKey($teacherUser->id, $byUser);
        $this->assertArrayNotHasKey($student->id, $byUser);

        $row = $byUser->get($staff->id);
        $this->assertSame('персонал', $row['category']);
        $this->assertSame(30000.0, $row['monthly_rate']);
        $this->assertSame(1, $row['silent_months']);
        $this->assertSame(30000.0, $row['owed_estimate']);
        $this->assertTrue($row['assumption']);

        $oneoffRow = $byUser->get($oneoff->id);
        $this->assertSame('разовый', $oneoffRow['category']);
        $this->assertNull($oneoffRow['monthly_rate']);
        $this->assertSame(0.0, $oneoffRow['owed_estimate']);

        $this->assertSame(30000.0, $out['totals']['monthly_estimate']);
        $this->assertSame(30000.0, $out['totals']['owed_estimate']);
        $this->assertFalse($out['money_tables_moved']);
    }

    /** @test */
    public function collection_readiness_counts_unpaid_students_of_teacher_courses(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Препод Фикстура']);
        $course = Course::factory()->create(['is_active' => true]);
        $course->forceFill(['teacher_id' => $teacher->id])->saveQuietly();
        Tariff::factory()->block(2)->create(['course_id' => $course->id, 'price' => 6000]);

        CourseBlock::factory()
            ->withDates(now()->subDays(10), now()->subDays(3))
            ->create(['course_id' => $course->id, 'number' => 2]);

        $group = Group::factory()->create();
        $group->courses()->attach($course->id);

        $payer = User::factory()->create(['name' => 'Платёж Фикстура']);
        $debtor = User::factory()->create(['name' => 'Должник Фикстура']);
        $free = User::factory()->create(['name' => 'Льготник Фикстура']);
        $group->users()->attach([$payer->id, $debtor->id, $free->id]);
        \DB::table('course_user')->insert([
            'user_id' => $free->id,
            'course_id' => $course->id,
            'status' => 'Льготник',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            [$payer, 2],
            [$debtor, 1], // заплатил только блок 1 → должен за блок 2
        ] as [$u, $block]) {
            $p = Payment::create([
                'user_id' => $u->id,
                'course_id' => $course->id,
                'amount' => 6000,
                'tariff' => 'block_'.$block,
                'status' => 'paid',
                'start_block' => $block,
                'end_block' => $block,
            ]);
            $p->forceFill(['created_at' => now()->subDays(12)])->saveQuietly();
        }

        $debts = app(PayrollContourService::class)->collectionReadinessByTeacher();
        $row = $debts[$teacher->id] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(1, $row['pairs']);
        $this->assertSame(6000.0, $row['amount']);
        $this->assertSame(6000.0, $row['by_course'][$course->id]);
    }

    /** @test */
    public function recent_payments_slice_reports_student_amount_and_delay(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Препод Срез']);
        $course = Course::factory()->create(['is_active' => true]);
        $course->forceFill(['teacher_id' => $teacher->id])->saveQuietly();

        CourseBlock::factory()
            ->withDates(now()->subDays(12)->startOfDay(), now()->subDays(5))
            ->create(['course_id' => $course->id, 'number' => 2]);

        $late = User::factory()->create(['name' => 'Опоздавший Фикстура']);
        $early = User::factory()->create(['name' => 'Заранее Фикстура']);

        foreach ([[$late, now()->subDays(2)], [$early, now()->subDays(15)]] as [$u, $when]) {
            $p = Payment::create([
                'user_id' => $u->id,
                'course_id' => $course->id,
                'amount' => 6000,
                'tariff' => 'block_2',
                'status' => 'paid',
                'start_block' => 2,
                'end_block' => 2,
            ]);
            $p->forceFill(['created_at' => $when])->saveQuietly();
        }

        $out = app(PayrollContourService::class)->recentPaymentsByTeacher(35);
        $row = collect($out)->firstWhere('teacher_id', $teacher->id);

        $this->assertNotNull($row);
        $this->assertCount(2, $row['rows']);

        $byStudent = collect($row['rows'])->keyBy('student');
        $this->assertSame(6000.0, $byStudent->get('Опоздавший Фикстура')['amount']);
        $this->assertSame('№2', $byStudent->get('Опоздавший Фикстура')['blocks']);
        $this->assertGreaterThan(3, $byStudent->get('Опоздавший Фикстура')['delay_days']);

        $earlyRow = $byStudent->get('Заранее Фикстура');
        $this->assertLessThan(0, $earlyRow['delay_days']);
    }

    /** @test */
    public function calendar_page_renders_contour_sections_for_finance_role(): void
    {
        config(['features.teacher_weekly_payout_calendar' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $response = $this->get('/admin/teacher-weekly-payout-calendar');
        $response->assertSuccessful();
        $response->assertSee('Недельный ритуал владельца');
        $response->assertSee('Собранность должников');
        $response->assertSee('Весь контур');

        $this->assertSame(0, TeacherPayout::query()->count());
    }
}
