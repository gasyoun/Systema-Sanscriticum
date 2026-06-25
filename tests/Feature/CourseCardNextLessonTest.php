<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Карточка курса в кабинете (/dvaram) ведёт прямо в «следующий урок» — первый
 * по порядку доступный и ещё не пройденный — и называет его. P0-онбординг.
 */
class CourseCardNextLessonTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Course, 2: \Illuminate\Support\Collection<int, Lesson>} */
    private function enrolledStudentWithLessons(int $lessonCount = 3): array
    {
        $course = Course::factory()->create(['title' => 'Грамматика санскрита']);
        $group = Group::create(['name' => 'Поток 1']);
        $course->groups()->attach($group);

        $lessons = collect(range(1, $lessonCount))->map(fn (int $i) => Lesson::factory()->for($course)->create([
            'title' => "Урок {$i}",
            'sort_order' => $i,
            'group_id' => null,
        ]));

        $student = User::factory()->create();
        $student->groups()->attach($group);

        return [$student, $course, $lessons];
    }

    private function complete(User $student, Lesson $lesson): void
    {
        $student->lessonProgress()->attach($lesson->id, ['is_completed' => true]);
    }

    /** @test */
    public function fresh_course_points_to_the_first_lesson(): void
    {
        [$student, $course, $lessons] = $this->enrolledStudentWithLessons();

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Первый урок:')
            ->assertSee('Урок 1')
            ->assertSee(route('student.lesson', [$course->slug, $lessons[0]->id]), false)
            ->assertSee('Начать обучение');
    }

    /** @test */
    public function partially_completed_course_points_to_the_next_unfinished_lesson(): void
    {
        [$student, $course, $lessons] = $this->enrolledStudentWithLessons();
        $this->complete($student, $lessons[0]);

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Следующий урок:')
            ->assertSee('Урок 2')
            ->assertSee(route('student.lesson', [$course->slug, $lessons[1]->id]), false)
            ->assertSee('Продолжить');
    }

    /** @test */
    public function fully_completed_course_shows_done_state_and_links_to_course(): void
    {
        [$student, $course, $lessons] = $this->enrolledStudentWithLessons();
        $lessons->each(fn (Lesson $lesson) => $this->complete($student, $lesson));

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Все уроки пройдены')
            ->assertSee(route('student.course', $course->slug), false)
            ->assertDontSee('Следующий урок:');
    }

    /** @test */
    public function next_lesson_skips_lessons_restricted_to_another_group(): void
    {
        [$student, $course, $lessons] = $this->enrolledStudentWithLessons();
        // Урок 1 заперт за чужую группу → недоступен студенту, «следующим» должен
        // стать первый доступный (Урок 2).
        $otherGroup = Group::create(['name' => 'Поток 2']);
        $lessons[0]->update(['group_id' => $otherGroup->id]);

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Урок 2')
            ->assertSee(route('student.lesson', [$course->slug, $lessons[1]->id]), false);
    }
}
