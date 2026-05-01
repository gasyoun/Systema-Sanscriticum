<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LessonAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** @test */
    public function guest_is_redirected_when_opening_a_lesson(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->free()->create();

        $this->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertRedirect('/login');
    }

    /** @test */
    public function free_lesson_is_accessible_without_paid_tariff(): void
    {
        $user   = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->free()->create(['block_number' => 1]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function paid_lesson_redirects_back_to_course_when_user_has_no_tariff(): void
    {
        $user   = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'is_free'      => false,
            'block_number' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertRedirect(route('student.course', $course->slug));
    }
}
