<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Jobs\BuildHomeworkImagesPdfJob;
use App\Mail\HomeworkSubmittedMail;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\HomeworkImagePdfService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Регрессия H3092 → H3095: уведомление проверяющего не должно зависеть от
 * исхода сборки `combined-images.pdf`.
 *
 * 18-08-2026 сборка съедала память php-fpm и роняла САМ POST сдачи: работа
 * уже лежала в базе, студент получал 500, а `notifyTeacher()`, стоявший в коде
 * ЗА сборкой, не выполнялся — проверяющий о работе не узнавал. H3092 убрал
 * известный вход (ужатие страниц), H3095 убирает класс: сборка ушла в
 * `BuildHomeworkImagesPdfJob`, а постановка в очередь обёрнута в `try/catch`.
 *
 * Очередь здесь НЕ подменяется (`QUEUE_CONNECTION=sync` из phpunit.xml), то
 * есть джоба выполняется прямо внутри запроса — самый враждебный из реальных
 * раскладов. Если бы страховки не было, брошенное из сборки исключение
 * прилетело бы в POST ровно как раньше.
 */
class HomeworkPdfFailureDoesNotLoseNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    /** @test */
    public function submission_survives_and_reviewer_is_notified_when_pdf_build_throws(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-pdf-fail@example.test']);
        User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сделайте упражнение',
            'is_free' => true,
            'block_number' => 2,
        ]);
        $student = User::factory()->create(['name' => 'Иван Тестов']);

        // Сборка PDF падает — как падала бы на исчерпании памяти.
        $this->app->bind(HomeworkImagePdfService::class, fn () => new class extends HomeworkImagePdfService
        {
            public function rebuild(HomeworkSubmission $submission): ?string
            {
                throw new \RuntimeException('imitated PDF build failure');
            }
        });

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Решение фото',
                'files' => [UploadedFile::fake()->image('page1.jpg', 200, 150)],
            ]
        )->assertRedirect();

        // 1. Работа сохранена и сдана.
        $submission = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->first();
        $this->assertNotNull($submission);
        $this->assertSame(HomeworkSubmission::STATUS_SUBMITTED, $submission->status);

        // 2. Проверяющий уведомлён, хотя сборка упала.
        Mail::assertQueued(HomeworkSubmittedMail::class, fn (HomeworkSubmittedMail $m) => (int) $m->submission->id === (int) $submission->id);

        // 3. PDF нет — и письмо честно уходит без вложения, а не ждёт его.
        Mail::assertQueued(HomeworkSubmittedMail::class, fn (HomeworkSubmittedMail $m) => $m->hasImagesPdfAttachment() === false
            && $m->attachments() === []);
    }

    /** @test */
    public function job_carries_only_the_submission_id_and_survives_a_deleted_submission(): void
    {
        // Работу удалили, пока сборка ждала очереди — job не должна падать.
        $job = new BuildHomeworkImagesPdfJob(999_999);

        $job->handle(app(HomeworkImagePdfService::class));

        $this->assertSame('imports', $job->queue);
        $this->assertSame(1, $job->tries);
    }
}
