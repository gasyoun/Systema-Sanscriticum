<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\HomeworkSubmissionResource\Pages\ListHomeworkSubmissions;
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

class HomeworkReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function submission(Course $course, Lesson $lesson, string $status = HomeworkSubmission::STATUS_SUBMITTED): HomeworkSubmission
    {
        return HomeworkSubmission::create([
            'user_id' => User::factory()->create()->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => $status,
            'last_activity_at' => now(),
        ]);
    }

    /** @test */
    public function bulk_accept_marks_submitted_works_accepted_and_skips_already_accepted(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();

        $submitted = $this->submission($course, $lesson);
        $alreadyAccepted = $this->submission($course, $lesson, HomeworkSubmission::STATUS_ACCEPTED);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListHomeworkSubmissions::class)
            ->callTableBulkAction('accept', [$submitted, $alreadyAccepted], data: ['body' => 'Отлично', 'grade' => HomeworkSubmission::GRADE_5])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(HomeworkSubmission::STATUS_ACCEPTED, $submitted->fresh()->status);
        $this->assertSame(HomeworkSubmission::GRADE_5, $submitted->fresh()->grade);
        $this->assertSame($admin->id, $submitted->fresh()->reviewed_by);
        // Запись комментария-вердикта создана только для реально обработанной работы.
        $this->assertSame(1, $submitted->fresh()->comments()->count());
        $this->assertSame(0, $alreadyAccepted->fresh()->comments()->count());
    }

    /** @test */
    public function homework_resource_query_exposes_student_file_size_for_submission(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();
        $submission = $this->submission($course, $lesson);

        $studentComment = $submission->comments()->create([
            'author_id' => $submission->user_id,
            'author_role' => HomeworkComment::ROLE_STUDENT,
            'type' => HomeworkComment::TYPE_SUBMISSION,
        ]);
        $teacherComment = $submission->comments()->create([
            'author_id' => $admin->id,
            'author_role' => HomeworkComment::ROLE_TEACHER,
            'type' => HomeworkComment::TYPE_REVIEW,
        ]);
        $studentComment->files()->create([
            'disk' => 'local',
            'path' => 'homework/a.pdf',
            'original_name' => 'a.pdf',
            'size' => 2048,
        ]);
        $teacherComment->files()->create([
            'disk' => 'local',
            'path' => 'homework/feedback.pdf',
            'original_name' => 'feedback.pdf',
            'size' => 4096,
        ]);

        $this->actingAs($admin);

        $record = \App\Filament\Resources\HomeworkSubmissionResource::getEloquentQuery()->findOrFail($submission->id);

        $this->assertSame(2048, $record->studentFilesBytes());
        $this->assertSame(1, $record->studentFilesCount());
        $this->assertSame('2 КБ', HomeworkFile::humanSizeFor($record->studentFilesBytes()));
    }

    /** @test */
    public function bulk_return_requires_a_comment(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();
        $submitted = $this->submission($course, $lesson);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListHomeworkSubmissions::class)
            ->callTableBulkAction('return', [$submitted], data: ['body' => ''])
            ->assertHasTableBulkActionErrors(['body' => 'required']);

        // Статус не изменился — вердикт не вынесен.
        $this->assertSame(HomeworkSubmission::STATUS_SUBMITTED, $submitted->fresh()->status);
    }

    /** @test */
    public function bulk_return_sends_work_back_with_comment(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();
        $submitted = $this->submission($course, $lesson);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListHomeworkSubmissions::class)
            ->callTableBulkAction('return', [$submitted], data: ['body' => 'Поправьте транслитерацию'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(HomeworkSubmission::STATUS_NEEDS_REVISION, $submitted->fresh()->status);
        $this->assertSame('Поправьте транслитерацию', $submitted->fresh()->comments()->first()->body);
    }
}
