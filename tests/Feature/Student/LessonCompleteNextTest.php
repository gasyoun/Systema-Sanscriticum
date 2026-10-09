<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\CourseQuiz;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Завершить и перейти к следующему» (мини-курсы): completeLesson умеет
 * редиректить дальше — на следующий урок или на квиз этапа (next=quiz).
 * Чужие/несуществующие id в next молча дают фолбэк «назад».
 */
class LessonCompleteNextTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Course $course;

    private Lesson $lessonOne;

    private Lesson $lessonTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $group = Group::create(['name' => 'G-complete-next']);
        $this->user->groups()->attach($group);

        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'complete-next']);
        $this->course->groups()->attach($group);

        $this->lessonOne = Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $group->id,
            'is_free' => true,
            'title' => 'Урок 1',
            'block_number' => 1,
        ]);
        $this->lessonTwo = Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $group->id,
            'is_free' => true,
            'title' => 'Урок 2',
            'block_number' => 1,
        ]);
    }

    /** @test */
    public function complete_with_next_lesson_id_redirects_forward(): void
    {
        $this->actingAs($this->user)
            ->post(route('student.lesson.complete', [$this->course->slug, $this->lessonOne->id]), [
                'next' => $this->lessonTwo->id,
            ])
            ->assertRedirect(route('student.lesson', [$this->course->slug, $this->lessonTwo->id]));

        $this->assertTrue($this->user->completedLessons()->where('lesson_id', $this->lessonOne->id)->exists());
    }

    /** @test */
    public function complete_last_lesson_with_next_quiz_redirects_to_quiz(): void
    {
        CourseQuiz::create([
            'course_id' => $this->course->id,
            'block_number' => 1,
            'title' => 'Квиз этапа 1',
            'pass_score' => 60,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->post(route('student.lesson.complete', [$this->course->slug, $this->lessonTwo->id]), [
                'next' => 'quiz',
            ])
            ->assertRedirect(route('student.course.quiz', [$this->course->slug, 1]));

        $this->assertTrue($this->user->completedLessons()->where('lesson_id', $this->lessonTwo->id)->exists());
    }

    /** @test */
    public function foreign_lesson_id_in_next_falls_back_to_back(): void
    {
        $otherCourse = Course::factory()->create(['is_active' => true]);
        $foreignLesson = Lesson::factory()->create(['course_id' => $otherCourse->id, 'is_free' => true]);

        $from = route('student.lesson', [$this->course->slug, $this->lessonOne->id]);

        $this->actingAs($this->user)
            ->from($from)
            ->post(route('student.lesson.complete', [$this->course->slug, $this->lessonOne->id]), [
                'next' => $foreignLesson->id,
            ])
            ->assertRedirect($from);
    }

    /** @test */
    public function lesson_page_shows_forward_button_and_next_link(): void
    {
        $page = $this->actingAs($this->user)
            ->get(route('student.lesson', [$this->course->slug, $this->lessonOne->id]))
            ->assertOk();

        $page->assertSee('Завершить и перейти к следующему уроку');
        $page->assertDontSee('>Следующий урок');

        // После завершения: бейдж «Пройден» + линк вперёд на урок 2.
        $this->user->lessonProgress()->attach($this->lessonOne->id, ['is_completed' => true]);
        $this->user->refresh();

        $this->actingAs($this->user)
            ->get(route('student.lesson', [$this->course->slug, $this->lessonOne->id]))
            ->assertOk()
            ->assertSee('Пройден')
            ->assertSee(route('student.lesson', [$this->course->slug, $this->lessonTwo->id]));
    }
}
