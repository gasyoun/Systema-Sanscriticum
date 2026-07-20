<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Bot\StudentSelfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentSelfServiceHomeworkTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function empty_state_when_no_submissions(): void
    {
        $user = User::factory()->create();

        $summary = app(StudentSelfService::class)->homeworkSummary($user);

        $this->assertStringContainsString('Пока нет отправленных домашних работ', $summary);
    }

    /** @test */
    public function lists_submissions_with_status_and_link(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create(['title' => 'Санскрит для начинающих']);

        HomeworkSubmission::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
            'last_activity_at' => now(),
        ]);

        $summary = app(StudentSelfService::class)->homeworkSummary($user);

        $this->assertStringContainsString('Санскрит для начинающих', $summary);
        $this->assertStringContainsString('На доработку', $summary);
        $this->assertStringContainsString(route('student.lesson', [$course->slug, $lesson->id]), $summary);
    }
}
