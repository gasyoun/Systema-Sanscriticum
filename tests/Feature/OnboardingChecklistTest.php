<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use App\Support\OnboardingChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingChecklistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Студент с доступом к курсу, у которого есть урок с ДЗ.
     *
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function studentWithCourse(bool $withHomework = true): array
    {
        $course = Course::factory()->create(['is_active' => true]);
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        $user = User::factory()->create();
        $user->groups()->attach($group->id);

        $lesson = Lesson::factory()->for($course)->create([
            'group_id' => null,
            'homework_enabled' => $withHomework,
        ]);

        return [$user, $course, $lesson];
    }

    /** @test */
    public function fresh_student_has_no_completed_steps(): void
    {
        [$user] = $this->studentWithCourse();

        $checklist = OnboardingChecklist::for($user);

        $this->assertSame(0, $checklist['completed']);
        $this->assertSame(4, $checklist['total']);
        $this->assertFalse($checklist['allComplete']);
        // Ссылка на курс проставлена для шагов с уроком.
        $this->assertNotNull($checklist['steps'][0]['url']);
    }

    /** @test */
    public function all_steps_complete_hides_the_card(): void
    {
        [$user, $course, $lesson] = $this->studentWithCourse();

        LessonView::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'open_count' => 1,
            'first_opened_at' => now(),
            'last_opened_at' => now(),
        ]);
        $user->lessonProgress()->attach($lesson->id, ['is_completed' => true]);
        HomeworkSubmission::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);
        $user->update(['telegram_id' => '12345']);

        $checklist = OnboardingChecklist::for($user->fresh());

        $this->assertSame(4, $checklist['completed']);
        $this->assertTrue($checklist['allComplete']);
    }

    /** @test */
    public function homework_step_auto_completes_when_course_has_no_homework(): void
    {
        [$user] = $this->studentWithCourse(withHomework: false);

        $checklist = OnboardingChecklist::for($user);

        $homeworkStep = collect($checklist['steps'])->firstWhere('key', 'homework');
        $this->assertTrue($homeworkStep['done'], 'Без уроков с ДЗ шаг считается выполненным.');
    }

    /** @test */
    public function dashboard_renders_checklist_for_new_student(): void
    {
        [$user] = $this->studentWithCourse();

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('С чего начать')
            ->assertSee('Откройте первый урок');
    }
}
