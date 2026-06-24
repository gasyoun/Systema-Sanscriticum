<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentProgressCardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function learning_progress_computes_completion_percent_per_course(): void
    {
        $student = User::factory()->create();
        $course = Course::factory()->create();
        $student->courses()->attach($course->id);

        // 4 урока, видимых всем (group_id = null) → знаменатель = 4.
        $lessons = Lesson::factory()->count(4)->for($course)->create(['group_id' => null]);

        // 2 из них реально пройдены (pivot is_completed = true).
        $student->lessonProgress()->attach($lessons[0]->id, ['is_completed' => true]);
        $student->lessonProgress()->attach($lessons[1]->id, ['is_completed' => true]);
        // Третий открыт, но не завершён — в знаменатель попадает, в числитель нет.
        $student->lessonProgress()->attach($lessons[2]->id, ['is_completed' => false]);

        $progress = UserResource::learningProgress($student);

        $this->assertCount(1, $progress['courses']);
        $this->assertSame($course->title, $progress['courses'][0]['title']);
        $this->assertSame(4, $progress['courses'][0]['total']);
        $this->assertSame(2, $progress['courses'][0]['completed']);
        $this->assertSame(50, $progress['courses'][0]['percent']);
    }

    /** @test */
    public function learning_progress_summarises_homework_by_status(): void
    {
        $student = User::factory()->create();
        $course = Course::factory()->create();

        // Уникальный ключ (user_id, lesson_id) → одна работа на урок: даём свой урок каждой.
        foreach ([
            HomeworkSubmission::STATUS_SUBMITTED,
            HomeworkSubmission::STATUS_NEEDS_REVISION,
            HomeworkSubmission::STATUS_ACCEPTED,
            HomeworkSubmission::STATUS_ACCEPTED,
            HomeworkSubmission::STATUS_DRAFT, // черновик в сводку не считаем явно — только три статуса
        ] as $status) {
            $lesson = Lesson::factory()->for($course)->create(['group_id' => null]);
            HomeworkSubmission::create([
                'user_id' => $student->id,
                'lesson_id' => $lesson->id,
                'course_id' => $course->id,
                'status' => $status,
                'last_activity_at' => now(),
            ]);
        }

        $hw = UserResource::learningProgress($student)['homework'];

        $this->assertSame(1, $hw['submitted']);
        $this->assertSame(1, $hw['needs_revision']);
        $this->assertSame(2, $hw['accepted']);
    }

    /** @test */
    public function learning_progress_handles_student_with_no_courses(): void
    {
        $student = User::factory()->create();

        $progress = UserResource::learningProgress($student);

        $this->assertSame([], $progress['courses']);
        $this->assertSame(0, $progress['homework']['submitted']);
    }

    /** @test */
    public function view_card_renders_progress_section_for_admin(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $student = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Санскрит для начинающих']);
        $student->courses()->attach($course->id);
        $lessons = Lesson::factory()->count(2)->for($course)->create(['group_id' => null]);
        $student->lessonProgress()->attach($lessons[0]->id, ['is_completed' => true]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(\App\Filament\Resources\UserResource\Pages\ViewUser::class, [
            'record' => $student->getRouteKey(),
        ])
            ->assertOk()
            ->assertSee('Прогресс обучения')
            ->assertSee('Санскрит для начинающих')
            ->assertSee('50%');
    }
}
