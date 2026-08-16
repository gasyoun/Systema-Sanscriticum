<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\HomeworkSubmissionResource\Pages\ViewHomeworkSubmission;
use App\Models\Course;
use App\Models\HomeworkComment;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H2896 residual #7 — student-controlled filename must not break out of the
 * Filament homework-thread delete confirm (JS-in-attribute). The student
 * cabinet twin already uses @js(); this locks the staff thread to the same.
 */
class HomeworkFilamentThreadXssTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    public function test_filament_delete_confirm_json_encodes_hostile_filename(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-xss@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create(['homework_enabled' => true]);
        $student = User::factory()->create();
        $submission = HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);
        $comment = HomeworkComment::create([
            'submission_id' => $submission->id,
            'author_id' => $student->id,
            'author_role' => HomeworkComment::ROLE_STUDENT,
            'type' => HomeworkComment::TYPE_SUBMISSION,
            'body' => 'Сдача',
        ]);
        $hostile = "x\\');alert(1);//.jpg";
        HomeworkFile::create([
            'comment_id' => $comment->id,
            'disk' => 'local',
            'path' => 'homework/xss.jpg',
            'original_name' => $hostile,
            'size' => 12,
            'mime' => 'image/jpeg',
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $html = Livewire::test(ViewHomeworkSubmission::class, ['record' => $submission->id])
            ->html();

        $this->assertStringNotContainsString(
            'addslashes(',
            $html,
        );
        $this->assertStringNotContainsString(
            "');alert(1)",
            $html,
            'A raw JS string terminator + alert must not appear; @js must encode the quote.',
        );
        $this->assertTrue(
            str_contains($html, '\u0027') || str_contains($html, 'JSON.parse('),
            '@js() must encode the apostrophe (\\\\u0027 or JSON.parse), not interpolate it.',
        );
    }
}
