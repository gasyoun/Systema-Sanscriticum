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
 * H2142: move a homework file from one lesson's submission to another
 * (same student, same course) — wrong-lesson upload recovery.
 */
class HomeworkFileMoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
    }

    /**
     * @return array{
     *   teacherUser: User,
     *   course: Course,
     *   lessonA: Lesson,
     *   lessonB: Lesson,
     *   student: User,
     *   submissionA: HomeworkSubmission,
     *   file: HomeworkFile
     * }
     */
    private function seedTwoLessonsWithFileOnA(): array
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-move@example.test']);
        $teacherUser = User::factory()->create([
            'role' => Roles::TEACHER,
            'teacher_id' => $teacher->id,
        ]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lessonA = Lesson::factory()->for($course)->create([
            'title' => 'Урок 1',
            'homework_enabled' => true,
            'homework_prompt' => 'ДЗ-1',
            'is_free' => true,
        ]);
        $lessonB = Lesson::factory()->for($course)->create([
            'title' => 'Урок 2',
            'homework_enabled' => true,
            'homework_prompt' => 'ДЗ-2',
            'is_free' => true,
        ]);
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lessonA->id]),
            [
                'action' => 'submit',
                'body' => 'Это на самом деле ДЗ-2',
                'files' => [UploadedFile::fake()->create('dz2.pdf', 80, 'application/pdf')],
            ]
        )->assertRedirect();

        $submissionA = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lessonA->id)
            ->firstOrFail();
        $file = $submissionA->comments()->first()->files()->firstOrFail();

        return compact(
            'teacherUser',
            'course',
            'lessonA',
            'lessonB',
            'student',
            'submissionA',
            'file',
        );
    }

    /** @test */
    public function student_can_move_own_file_to_another_lesson(): void
    {
        extract($this->seedTwoLessonsWithFileOnA());

        $oldPath = $file->path;

        $this->actingAs($student)
            ->from(route('student.lesson', [$course->slug, $lessonA->id]))
            ->post(route('homework.file.move', $file), [
                'lesson_id' => $lessonB->id,
                'go_to_target' => '1',
            ])
            ->assertRedirect(route('student.lesson', [$course->slug, $lessonB->id]));

        $file->refresh();
        $this->assertNotEquals($oldPath, $file->path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($file->path);

        $submissionB = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lessonB->id)
            ->firstOrFail();
        $this->assertSame(HomeworkSubmission::STATUS_SUBMITTED, $submissionB->status);
        $this->assertTrue($submissionB->comments->flatMap->files->contains('id', $file->id));

        $noteA = $submissionA->fresh()->comments()->where('type', HomeworkComment::TYPE_MESSAGE)->latest('id')->first();
        $this->assertNotNull($noteA);
        $this->assertStringContainsString('перенесён', (string) $noteA->body);
        $this->assertStringContainsString('Урок 2', (string) $noteA->body);

        $noteB = $submissionB->comments()->where('type', HomeworkComment::TYPE_MESSAGE)->latest('id')->first();
        $this->assertNotNull($noteB);
        $this->assertStringContainsString('Урок 1', (string) $noteB->body);
    }

    /** @test */
    public function teacher_can_move_student_file(): void
    {
        extract($this->seedTwoLessonsWithFileOnA());

        $this->actingAs($teacherUser)
            ->from('/admin')
            ->post(route('homework.file.move', $file), ['lesson_id' => $lessonB->id])
            ->assertRedirect();

        $file->refresh();
        $submissionB = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lessonB->id)
            ->firstOrFail();
        $this->assertSame((int) $submissionB->id, (int) $file->comment->submission_id);
    }

    /** @test */
    public function stranger_cannot_move_file(): void
    {
        extract($this->seedTwoLessonsWithFileOnA());
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('homework.file.move', $file), ['lesson_id' => $lessonB->id])
            ->assertForbidden();

        $file->refresh();
        $this->assertSame((int) $submissionA->id, (int) $file->comment->submission_id);
    }

    /** @test */
    public function cannot_move_to_lesson_without_homework(): void
    {
        extract($this->seedTwoLessonsWithFileOnA());
        $lessonC = Lesson::factory()->for($course)->create([
            'title' => 'Без ДЗ',
            'homework_enabled' => false,
            'is_free' => true,
        ]);

        $this->actingAs($student)
            ->post(route('homework.file.move', $file), ['lesson_id' => $lessonC->id])
            ->assertStatus(422);
    }

    /** @test */
    public function staff_move_all_student_files(): void
    {
        extract($this->seedTwoLessonsWithFileOnA());

        $path2 = 'homework/'.$student->id.'/'.$lessonA->id.'/extra.pdf';
        Storage::disk('local')->put($path2, 'x');
        $comment = $submissionA->comments()->where('author_role', HomeworkComment::ROLE_STUDENT)->first();
        HomeworkFile::create([
            'comment_id' => $comment->id,
            'disk' => 'local',
            'path' => $path2,
            'original_name' => 'extra.pdf',
            'size' => 1,
            'mime' => 'application/pdf',
        ]);

        $moved = app(HomeworkService::class)->moveAllStudentFiles(
            $submissionA->fresh(['comments.files']),
            $lessonB,
            $teacherUser,
        );

        $this->assertSame(2, $moved);
        $submissionB = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lessonB->id)
            ->firstOrFail();
        $this->assertSame(2, $submissionB->comments->flatMap->files->count());
    }

    /** @test */
    public function student_cannot_move_teacher_feedback_file(): void
    {
        extract($this->seedTwoLessonsWithFileOnA());

        $path = 'homework/feedback/note-move.txt';
        Storage::disk('local')->put($path, 'note');
        $review = $submissionA->comments()->create([
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
            'original_name' => 'note-move.txt',
            'size' => 4,
            'mime' => 'text/plain',
        ]);
        $submissionA->update(['status' => HomeworkSubmission::STATUS_NEEDS_REVISION]);

        $this->actingAs($student)
            ->post(route('homework.file.move', $feedback), ['lesson_id' => $lessonB->id])
            ->assertForbidden();
    }
}
