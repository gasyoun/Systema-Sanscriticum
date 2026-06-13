<?php

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->free()->create(['block_number' => 1]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function paid_lesson_redirects_back_to_course_when_user_has_no_tariff(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'is_free' => false,
            'block_number' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertRedirect(route('student.course', $course->slug));
    }

    /** @test */
    public function paid_lesson_is_accessible_with_paid_status_payment(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'is_free' => false,
            'block_number' => 2,
        ]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertOk();
    }

    /**
     * Регрессия: платёж со статусом 'success' (часть путей сохранения, и сама
     * админка, пишут именно его) обязан открывать урок так же, как 'paid'.
     * До фикса student-гейт фильтровал только по 'paid', и урок оставался
     * закрытым, хотя PaymentObserver уже выдал группу за тот же success-платёж.
     *
     * @test
     */
    public function paid_lesson_is_accessible_with_success_status_payment(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'is_free' => false,
            'block_number' => 2,
        ]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'success',
        ]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertOk();
    }
}
