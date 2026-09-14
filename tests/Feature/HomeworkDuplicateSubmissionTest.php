<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\HomeworkSubmittedMail;
use App\Models\Course;
use App\Models\HomeworkFile;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H3524: повторная отправка ТОГО ЖЕ набора файлов при «зависшей» сдаче.
 * Пока работа submitted — полный дубль отклоняется с внятным сообщением
 * и не создаёт ни строк, ни повторного письма проверяющему. Частичный
 * набор, текст без файлов и сдача после needs_revision работают как раньше.
 */
class HomeworkDuplicateSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
    }

    private function makeTeacher(): Teacher
    {
        return Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
    }

    private function makeLessonWithHomework(Teacher $teacher): array
    {
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сделайте упражнение',
            'is_free' => true,
            'block_number' => 2,
        ]);

        return [$course, $lesson];
    }

    private function submitHomework(User $student, Course $course, Lesson $lesson, string $action, string $body, array $files)
    {
        return $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => $action, 'body' => $body, 'files' => $files],
        );
    }

    /**
     * @return array<int, UploadedFile> Новые инстансы с теми же именами/размерами.
     */
    private function sameFileSet(): array
    {
        return [
            UploadedFile::fake()->create('work.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->create('photo.jpg', 300, 'image/jpeg'),
        ];
    }

    /** @test */
    public function duplicate_file_set_while_submitted_is_blocked(): void
    {
        $teacher = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $this->submitHomework($student, $course, $lesson, 'submit', 'Моё решение', $this->sameFileSet())
            ->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->first();
        $this->assertNotNull($submission);
        $filesAfterFirst = HomeworkFile::whereHas('comment', fn ($q) => $q->where('submission_id', $submission->id))->count();
        $this->assertSame(1, $submission->comments()->count());

        $response = $this->submitHomework($student, $course, $lesson, 'submit', 'Повтор из-за зависания', $this->sameFileSet());

        $response->assertRedirect();
        $response->assertSessionHas('error', function ($error) {
            return str_contains((string) $error, 'уже приняты');
        });

        // Ни новой строки-сдачи, ни новых файлов, ни второго письма проверяющему.
        $submission->refresh();
        $this->assertSame(1, $submission->comments()->count());
        $this->assertSame(
            $filesAfterFirst,
            HomeworkFile::whereHas('comment', fn ($q) => $q->where('submission_id', $submission->id))->count(),
        );
        Mail::assertQueued(HomeworkSubmittedMail::class, 1);
    }

    /** @test */
    public function partial_file_set_still_updates_the_work(): void
    {
        $teacher = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $this->submitHomework($student, $course, $lesson, 'submit', 'Решение', [
            UploadedFile::fake()->create('work.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->create('photo.jpg', 300, 'image/jpeg'),
        ])->assertRedirect();

        // Один прежний + один новый файл — это осмысленное дополнение, не дубль.
        $this->submitHomework($student, $course, $lesson, 'submit', 'Добавил ещё', [
            UploadedFile::fake()->create('work.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->create('extra.pdf', 90, 'application/pdf'),
        ])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($s) => str_contains((string) $s, 'обновлена'));
    }

    /** @test */
    public function text_only_update_still_allowed(): void
    {
        $teacher = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $this->submitHomework($student, $course, $lesson, 'submit', 'Первый вариант', [
            UploadedFile::fake()->create('work.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->submitHomework($student, $course, $lesson, 'submit', 'Поправил текст', [])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($s) => str_contains((string) $s, 'обновлена'));
    }

    /** @test */
    public function same_files_allowed_again_after_needs_revision(): void
    {
        $teacher = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $this->submitHomework($student, $course, $lesson, 'submit', 'Решение', $this->sameFileSet())->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->first();
        $submission->update(['status' => HomeworkSubmission::STATUS_NEEDS_REVISION]);

        $this->submitHomework($student, $course, $lesson, 'submit', 'Исправил', $this->sameFileSet())
            ->assertRedirect()
            ->assertSessionHas('success', fn ($s) => str_contains((string) $s, 'отправлена на проверку'));
    }

    /** @test */
    public function first_submit_after_draft_with_same_files_is_allowed(): void
    {
        $teacher = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $this->submitHomework($student, $course, $lesson, 'draft', 'Черновик', $this->sameFileSet())
            ->assertRedirect()
            ->assertSessionHas('success', fn ($s) => str_contains((string) $s, 'Черновик'));

        $this->submitHomework($student, $course, $lesson, 'submit', 'Отправляю', $this->sameFileSet())
            ->assertRedirect()
            ->assertSessionHas('success', fn ($s) => str_contains((string) $s, 'отправлена на проверку'));
    }
}
