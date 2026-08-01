<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\HomeworkComment;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\HomeworkService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H2120: staff-side wipe of uploaded homework files.
 * Student delete while on review already lives in #1028/#1030.
 */
class HomeworkFileDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
    }

    private function seedSubmittedWork(): array
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $teacherUser = User::factory()->create([
            'role' => Roles::TEACHER,
            'teacher_id' => $teacher->id,
        ]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сделайте упражнение',
            'is_free' => true,
        ]);
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Моё решение',
                'files' => [UploadedFile::fake()->create('wrong.pdf', 80, 'application/pdf')],
            ]
        )->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();
        $file = $submission->comments()->first()->files()->first();

        return compact('teacher', 'teacherUser', 'course', 'lesson', 'student', 'submission', 'file');
    }

    /** @test */
    public function student_can_delete_own_file_while_submitted(): void
    {
        extract($this->seedSubmittedWork());

        $this->actingAs($student)
            ->from(route('student.lesson', [$course->slug, $lesson->id]))
            ->delete(route('homework.file.destroy', $file))
            ->assertRedirect();

        $this->assertDatabaseMissing('homework_files', ['id' => $file->id]);
        Storage::disk('local')->assertMissing($file->path);

        $note = $submission->fresh()->comments()->where('type', HomeworkComment::TYPE_MESSAGE)->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('wrong.pdf', (string) $note->body);
        $this->assertStringContainsString('Удалён файл', (string) $note->body);
    }

    /** @test */
    public function teacher_can_delete_student_file(): void
    {
        extract($this->seedSubmittedWork());

        $this->actingAs($teacherUser)
            ->from('/admin')
            ->delete(route('homework.file.destroy', $file))
            ->assertRedirect();

        $this->assertDatabaseMissing('homework_files', ['id' => $file->id]);
        Storage::disk('local')->assertMissing($file->path);

        $note = $submission->fresh()->comments()->where('type', HomeworkComment::TYPE_MESSAGE)->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('wrong.pdf', (string) $note->body);
    }

    /** @test */
    public function stranger_cannot_delete_file(): void
    {
        extract($this->seedSubmittedWork());
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->delete(route('homework.file.destroy', $file))
            ->assertForbidden();

        $this->assertDatabaseHas('homework_files', ['id' => $file->id]);
    }

    /** @test */
    public function staff_clear_student_files_reopens_for_revision(): void
    {
        extract($this->seedSubmittedWork());

        $deleted = app(HomeworkService::class)->clearStudentFiles(
            $submission,
            $teacherUser,
            reopenForRevision: true,
        );

        $this->assertSame(1, $deleted);
        $submission->refresh();
        $this->assertSame(HomeworkSubmission::STATUS_NEEDS_REVISION, $submission->status);
        $this->assertDatabaseMissing('homework_files', ['id' => $file->id]);
        Storage::disk('local')->assertMissing($file->path);

        $note = $submission->comments()->where('type', HomeworkComment::TYPE_MESSAGE)->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Удалены залитые файлы студента', (string) $note->body);
        $this->assertStringContainsString('wrong.pdf', (string) $note->body);
    }

    /** @test */
    public function student_cannot_delete_teacher_feedback_file(): void
    {
        extract($this->seedSubmittedWork());

        $path = 'homework/feedback/note.txt';
        Storage::disk('local')->put($path, 'note');
        $review = $submission->comments()->create([
            'author_id' => $teacherUser->id,
            'author_role' => HomeworkComment::ROLE_TEACHER,
            'type' => HomeworkComment::TYPE_REVIEW,
            'body' => 'Правки',
            'new_status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
        ]);
        $feedback = HomeworkFile::create([
            'comment_id' => $review->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'note.txt',
            'size' => 4,
            'mime' => 'text/plain',
        ]);
        $submission->update(['status' => HomeworkSubmission::STATUS_NEEDS_REVISION]);

        $this->actingAs($student)
            ->delete(route('homework.file.destroy', $feedback))
            ->assertForbidden();

        $this->assertDatabaseHas('homework_files', ['id' => $feedback->id]);
    }

    /** @test */
    public function teacher_can_delete_own_feedback_file(): void
    {
        extract($this->seedSubmittedWork());

        $path = 'homework/feedback/note2.txt';
        Storage::disk('local')->put($path, 'note');
        $review = $submission->comments()->create([
            'author_id' => $teacherUser->id,
            'author_role' => HomeworkComment::ROLE_TEACHER,
            'type' => HomeworkComment::TYPE_REVIEW,
            'body' => 'Правки',
            'new_status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
        ]);
        $feedback = HomeworkFile::create([
            'comment_id' => $review->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'note2.txt',
            'size' => 4,
            'mime' => 'text/plain',
        ]);

        $this->actingAs($teacherUser)
            ->delete(route('homework.file.destroy', $feedback))
            ->assertRedirect();

        $this->assertDatabaseMissing('homework_files', ['id' => $feedback->id]);
        Storage::disk('local')->assertMissing($path);
    }
}
