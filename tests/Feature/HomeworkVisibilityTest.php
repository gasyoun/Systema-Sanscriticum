<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendHomeworkNotificationJob;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\HomeworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HomeworkVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** @test */
    public function review_pushes_telegram_message_to_linked_student(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['services.telegram.student_bot_token' => 'TESTTOKEN']);

        $teacher = Teacher::create(['name' => 'T', 'email' => 't@example.test']);
        $teacherUser = User::factory()->create(['role' => \App\Support\Roles::TEACHER, 'teacher_id' => $teacher->id]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create(['title' => 'Урок о дхату']);
        $student = User::factory()->create(['telegram_id' => '99887766']);

        $submission = HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);

        app(HomeworkService::class)->recordReview(
            $submission, $teacherUser, HomeworkSubmission::STATUS_NEEDS_CHANGES, 'Поправьте сандхи'
        );

        Queue::assertPushed(SendHomeworkNotificationJob::class, fn ($job) => $job->event === \App\Services\HomeworkNotificationService::EVENT_RETURNED);
    }

    /** @test */
    public function review_does_not_push_when_no_messenger_linked(): void
    {
        Http::fake();

        $teacher = Teacher::create(['name' => 'T', 'email' => 't2@example.test']);
        $teacherUser = User::factory()->create(['role' => \App\Support\Roles::TEACHER, 'teacher_id' => $teacher->id]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();
        $student = User::factory()->create(['telegram_id' => null, 'vk_id' => null]);

        $submission = HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);

        app(HomeworkService::class)->recordReview(
            $submission, $teacherUser, HomeworkSubmission::STATUS_ACCEPTED, 'Отлично', [], HomeworkSubmission::GRADE_5
        );

        Http::assertNothingSent();
    }

    /** @test */
    public function dashboard_shows_returned_homework_alert(): void
    {
        $course = Course::factory()->create(['title' => 'Грамматика']);
        $lesson = Lesson::factory()->for($course)->create(['title' => 'Урок про сандхи']);
        $student = User::factory()->create();

        HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
            'last_activity_at' => now(),
            'reviewed_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('вернул работу на доработку')
            ->assertSee('Урок про сандхи');
    }

    /** @test */
    public function lesson_without_homework_shows_explicit_no_homework_state(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'is_free' => true,
            'homework_enabled' => false,
        ]);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('Домашнего задания для этого урока нет');
    }

    /** @test */
    public function lesson_with_enabled_but_empty_homework_shows_awaiting_state_and_hides_form(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'is_free' => true,
            'homework_enabled' => true,
            'homework_prompt' => null,
        ]);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('Задание ещё не задано')
            ->assertDontSee('Отправить на проверку');
    }
}
