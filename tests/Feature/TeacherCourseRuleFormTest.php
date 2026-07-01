<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LessonResource\Pages\CreateLesson;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Регресс на BindingResolutionException ($attribute unresolvable): Filament
 * вычисляет ->rule() как замыкание-продюсер, поэтому course_id-правило должно
 * быть обёрнуто (fn () => Course::teacherCourseValidationRule()). До фикса
 * любое сохранение формы урока/расписания/сертификата падало 500.
 */
class TeacherCourseRuleFormTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithBlock(int $teacherId): Course
    {
        $course = Course::factory()->create(['teacher_id' => $teacherId]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);

        return $course;
    }

    public function test_admin_can_save_lesson_form_without_rule_crash(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $teacher = Teacher::create(['name' => 'Основной']);
        $course = $this->courseWithBlock($teacher->id);

        Livewire::actingAs($admin)
            ->test(CreateLesson::class)
            ->fillForm([
                'course_id' => $course->id,
                'title' => 'Урок 1',
                'block_number' => 1,
                'lesson_date' => '2026-02-01 10:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('lessons', ['course_id' => $course->id, 'title' => 'Урок 1']);
    }

    public function test_co_teacher_passes_course_rule_via_pivot(): void
    {
        $primary = Teacher::create(['name' => 'Основной']);
        $co = Teacher::create(['name' => 'Со-препод']);
        $course = $this->courseWithBlock($primary->id);
        $course->teachers()->attach($co->id, ['salary_type' => 'percent', 'salary_value' => 20]);

        $coUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $co->id]);

        Livewire::actingAs($coUser)
            ->test(CreateLesson::class)
            ->fillForm([
                'course_id' => $course->id,
                'title' => 'Урок со-препода',
                'block_number' => 1,
                'lesson_date' => '2026-02-02 10:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('lessons', ['course_id' => $course->id, 'title' => 'Урок со-препода']);
    }
}
