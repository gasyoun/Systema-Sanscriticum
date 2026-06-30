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
use Tests\TestCase;

class MultiTeacherSalaryTest extends TestCase
{
    use RefreshDatabase;

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

    /** Модельные примитивы: основной, со-препод и чужой. */
    public function test_course_teacher_helpers_resolve_primary_and_co_teacher(): void
    {
        $a = Teacher::create(['name' => 'Основной A']);
        $b = Teacher::create(['name' => 'Со-препод B']);
        $c = Teacher::create(['name' => 'Чужой C']);

        $course = Course::factory()->create([
            'teacher_id' => $a->id, 'salary_type' => 'percent', 'salary_value' => 30,
        ]);
        $course->teachers()->attach($b->id, ['salary_type' => 'percent', 'salary_value' => 20]);

        // isTaughtBy
        $this->assertTrue($course->isTaughtBy($a->id));
        $this->assertTrue($course->isTaughtBy($b->id));
        $this->assertFalse($course->isTaughtBy($c->id));
        $this->assertFalse($course->isTaughtBy(null));

        // scopeForTeacher
        $this->assertTrue(Course::query()->forTeacher($a->id)->whereKey($course->id)->exists());
        $this->assertTrue(Course::query()->forTeacher($b->id)->whereKey($course->id)->exists());
        $this->assertFalse(Course::query()->forTeacher($c->id)->whereKey($course->id)->exists());
        $this->assertFalse(Course::query()->forTeacher(null)->exists());

        // salaryTermsFor: основной — с курса, со-препод — из pivot, чужой — null.
        $this->assertSame(['type' => 'percent', 'value' => 30.0], $course->salaryTermsFor($a->id));
        $this->assertSame(['type' => 'percent', 'value' => 20.0], $course->salaryTermsFor($b->id));
        $this->assertNull($course->salaryTermsFor($c->id));
    }

    /** Независимое начисление: каждый препод получает свой % от ОДНОЙ выручки. */
    public function test_two_teachers_accrue_independently_from_the_same_revenue(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $a = Teacher::create(['name' => 'A']);
        $b = Teacher::create(['name' => 'B']);

        $course = Course::factory()->create([
            'teacher_id' => $a->id, 'salary_type' => 'percent', 'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true, 'starts_at' => '2026-01-01']);
        $course->teachers()->attach($b->id, ['salary_type' => 'percent', 'salary_value' => 20]);

        $user = User::factory()->create();
        $this->pay($course, ['user_id' => $user->id, 'amount' => 10000, 'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1]);

        $service = app(TeacherSalaryService::class);

        // Каждый — от полной выручки 10 000 по своей ставке.
        $this->assertEqualsWithDelta(3000.0, $service->totalForTeacher($a->fresh()), 0.01);
        $this->assertEqualsWithDelta(2000.0, $service->totalForTeacher($b->fresh()), 0.01);

        // Строки разбивки несут эффективные условия каждого.
        $rowA = collect($service->breakdownForTeacher($a->fresh())['rows'])->firstWhere('course_id', $course->id);
        $rowB = collect($service->breakdownForTeacher($b->fresh())['rows'])->firstWhere('course_id', $course->id);
        $this->assertSame(30.0, $rowA['salary_value']);
        $this->assertSame(20.0, $rowB['salary_value']);
    }

    /** Регресс: курс с одним преподом считается как раньше. */
    public function test_single_teacher_course_unchanged(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $a = Teacher::create(['name' => 'A']);
        $course = Course::factory()->create([
            'teacher_id' => $a->id, 'salary_type' => 'percent', 'salary_value' => 30,
        ]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true, 'starts_at' => '2026-01-01']);

        $user = User::factory()->create();
        $this->pay($course, ['user_id' => $user->id, 'amount' => 10000, 'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1]);

        $this->assertEqualsWithDelta(3000.0, app(TeacherSalaryService::class)->totalForTeacher($a->fresh()), 0.01);
    }
}
