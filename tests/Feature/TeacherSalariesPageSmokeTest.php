<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherSalaries;
use App\Mail\TeacherPayoutReportMail;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TeacherSalariesPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_salaries_dashboard_renders_for_accountant(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $teacher = Teacher::create(['name' => 'Иван Преподавалов']);
        Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 10,
        ]);

        $this->actingAs($accountant)->get('/admin/teacher-salaries')->assertSuccessful();
    }

    public function test_payouts_resource_renders_for_accountant(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $this->actingAs($accountant)->get('/admin/teacher-payouts')->assertSuccessful();
    }

    public function test_regular_admin_cannot_access_salaries(): void
    {
        // После ввода роли «Бухгалтер» зарплаты/выплаты закрыты для обычного admin.
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $this->actingAs($admin)->get('/admin/teacher-salaries')->assertForbidden();
    }

    public function test_manager_cannot_access_salaries(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_admin' => true]);

        $this->actingAs($manager)->get('/admin/teacher-salaries')->assertForbidden();
    }

    public function test_widget_period_follows_the_table_filter(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $component = Livewire::actingAs($accountant)->test(TeacherSalaries::class);
        $component->set('tableFilters.period.value', '2026-03');

        // Период, прокидываемый в header-виджет, следует за фильтром таблицы.
        $this->assertSame('2026-03', $component->instance()->getWidgetData()['period']);
    }

    public function test_block_payout_calculator_records_payout(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $teacher = Teacher::create(['name' => 'Екатерина Костина']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 5, 'is_active' => true]);

        Livewire::actingAs($accountant)
            ->test(TeacherSalaries::class)
            ->callAction('block_payout', data: [
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'block_number' => 5,
                'group_id' => null,
                'base_revenue' => 48000,
                'coefficient' => 92,
                'teacher_percent' => 30,
                'extras' => [
                    ['description' => 'Доп. занятие', 'count' => 2, 'price' => 1500],
                ],
            ])
            ->assertHasNoActionErrors();

        $payout = TeacherPayout::query()->where('teacher_id', $teacher->id)->first();
        $this->assertNotNull($payout);
        $this->assertEqualsWithDelta(16008.0, (float) $payout->amount, 0.01);
        $this->assertSame(5, $payout->breakdown['block_number']);
        $this->assertEqualsWithDelta(3000.0, (float) $payout->breakdown['extras_total'], 0.01);
        // Регресс: процентная модель сохраняется как percent.
        $this->assertSame('percent', $payout->salary_type);
    }

    public function test_block_payout_uses_fixed_per_student_rate_not_percent(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $teacher = Teacher::create(['name' => 'Екатерина Костина']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'fix_per_student',
            'salary_value' => 1656,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);

        Livewire::actingAs($accountant)
            ->test(TeacherSalaries::class)
            ->callAction('block_payout', data: [
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'salary_type' => 'fix_per_student',
                'block_number' => 1,
                'group_id' => null,
                'fixed_rate' => 1656,
                'student_count' => 10,
                'coefficient' => null, // пусто = 100%, фикс «как есть»
            ])
            ->assertHasNoActionErrors();

        $payout = TeacherPayout::query()->where('teacher_id', $teacher->id)->first();
        $this->assertNotNull($payout);
        $this->assertEqualsWithDelta(16560.0, (float) $payout->amount, 0.01); // 1656 × 10
        $this->assertSame('fix_per_student', $payout->salary_type);
        $this->assertEqualsWithDelta(1656.0, (float) $payout->salary_value, 0.01);
        $this->assertSame(10, $payout->breakdown['student_count']);
    }

    public function test_block_payout_fixed_per_student_applies_optional_coefficient(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $teacher = Teacher::create(['name' => 'Екатерина']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'fix_per_student',
            'salary_value' => 1656,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);

        Livewire::actingAs($accountant)
            ->test(TeacherSalaries::class)
            ->callAction('block_payout', data: [
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'salary_type' => 'fix_per_student',
                'block_number' => 1,
                'group_id' => null,
                'fixed_rate' => 1656,
                'student_count' => 10,
                'coefficient' => 92, // применяется к фиксу
            ])
            ->assertHasNoActionErrors();

        $payout = TeacherPayout::query()->where('teacher_id', $teacher->id)->first();
        // 1656 × 10 × 92% = 15235.2
        $this->assertEqualsWithDelta(15235.2, (float) $payout->amount, 0.01);
    }

    public function test_block_payout_fixed_per_block_pays_flat_rate(): void
    {
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'fix_per_block',
            'salary_value' => 5000,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);

        Livewire::actingAs($accountant)
            ->test(TeacherSalaries::class)
            ->callAction('block_payout', data: [
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'salary_type' => 'fix_per_block',
                'block_number' => 1,
                'group_id' => null,
                'fixed_rate' => 5000,
                'coefficient' => null,
            ])
            ->assertHasNoActionErrors();

        $payout = TeacherPayout::query()->where('teacher_id', $teacher->id)->first();
        $this->assertEqualsWithDelta(5000.0, (float) $payout->amount, 0.01);
        $this->assertSame('fix_per_block', $payout->salary_type);
    }

    public function test_block_payout_converts_to_currency_and_emails_report(): void
    {
        Mail::fake();
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $teacher = Teacher::create([
            'name' => 'Екатерина',
            'email' => 'kat@example.test',
            'payout_currency' => 'EUR',
        ]);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);

        Livewire::actingAs($accountant)
            ->test(TeacherSalaries::class)
            ->callAction('block_payout', data: [
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'salary_type' => 'percent',
                'payout_currency' => 'EUR',
                'block_number' => 1,
                'group_id' => null,
                'base_revenue' => 48000,
                'coefficient' => 92,
                'teacher_percent' => 30,
                'exchange_rate' => 100,
                'rate_date' => '2026-05-08',
                'email_report' => true,
            ])
            ->assertHasNoActionErrors();

        $payout = TeacherPayout::query()->where('teacher_id', $teacher->id)->first();
        // Итог (48000 × 92%) × 30% = 13248 ₽, курс 100 → 132.48 €.
        $this->assertSame('EUR', $payout->payout_currency);
        $this->assertEqualsWithDelta(100.0, (float) $payout->exchange_rate, 0.0001);
        $this->assertEqualsWithDelta(132.48, (float) $payout->amount_foreign, 0.01);
        $this->assertSame('2026-05-08', $payout->rate_date->toDateString());

        Mail::assertQueued(TeacherPayoutReportMail::class, fn (TeacherPayoutReportMail $m) => $m->hasTo('kat@example.test'));
    }

    public function test_block_payout_without_currency_skips_conversion_and_email(): void
    {
        Mail::fake();
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $teacher = Teacher::create(['name' => 'Рублёвый', 'email' => 'rub@example.test']); // payout_currency = null
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);

        Livewire::actingAs($accountant)
            ->test(TeacherSalaries::class)
            ->callAction('block_payout', data: [
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'salary_type' => 'percent',
                'block_number' => 1,
                'group_id' => null,
                'base_revenue' => 48000,
                'coefficient' => 92,
                'teacher_percent' => 30,
            ])
            ->assertHasNoActionErrors();

        $payout = TeacherPayout::query()->where('teacher_id', $teacher->id)->first();
        $this->assertNull($payout->payout_currency);
        $this->assertNull($payout->amount_foreign);
        Mail::assertNothingQueued();
    }
}
