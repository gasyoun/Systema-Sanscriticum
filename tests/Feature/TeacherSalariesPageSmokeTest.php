<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherSalaries;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeacherSalariesPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_salaries_dashboard_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $teacher = Teacher::create(['name' => 'Иван Преподавалов']);
        Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 10,
        ]);

        $this->actingAs($admin)->get('/admin/teacher-salaries')->assertSuccessful();
    }

    public function test_payouts_resource_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $this->actingAs($admin)->get('/admin/teacher-payouts')->assertSuccessful();
    }

    public function test_manager_cannot_access_salaries(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_admin' => true]);

        $this->actingAs($manager)->get('/admin/teacher-salaries')->assertForbidden();
    }

    public function test_widget_period_follows_the_table_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $component = Livewire::actingAs($admin)->test(TeacherSalaries::class);
        $component->set('tableFilters.period.value', '2026-03');

        // Период, прокидываемый в header-виджет, следует за фильтром таблицы.
        $this->assertSame('2026-03', $component->instance()->getWidgetData()['period']);
    }

    public function test_block_payout_calculator_records_payout(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $teacher = Teacher::create(['name' => 'Екатерина Костина']);
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 5, 'is_active' => true]);

        Livewire::actingAs($admin)
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
    }
}
