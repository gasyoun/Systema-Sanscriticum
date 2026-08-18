<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomeworkImagesPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
    }

    private function makeLessonWithHomework(): array
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-pdf@example.test']);
        User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сделайте упражнение',
            'is_free' => true,
            'block_number' => 2,
        ]);

        return [$course, $lesson, $teacher];
    }

    /**
     * Выполнить сборки PDF, поставленные в очередь запросом (H3095).
     *
     * С 18-08-2026 `combined-images.pdf` собирается не на пути запроса, а
     * джобой на воркере. В тестах очередь подменена (`Queue::fake()`), поэтому
     * там, где проверяется сам PDF, воркера надо сыграть руками.
     *
     * @return int сколько сборок было в очереди
     */
    private function runQueuedImagesPdfJobs(): int
    {
        $jobs = Queue::pushed(BuildHomeworkImagesPdfJob::class);

        foreach ($jobs as $job) {
            $job->handle(app(HomeworkImagePdfService::class));
        }

        return $jobs->count();
    }

    /** @test */
    public function submit_with_images_builds_combined_pdf_and_attaches_to_mail(): void
    {
        [$course, $lesson] = $this->makeLessonWithHomework();
        $student = User::factory()->create(['name' => 'Иван Тестов']);

        $response = $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Решение фото',
                'files' => [
                    UploadedFile::fake()->image('page1.jpg', 200, 150),
                    UploadedFile::fake()->image('page2.png', 180, 120),
                ],
            ]
        );

        $response->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->first();
        $this->assertNotNull($submission);

        $pdf = app(HomeworkImagePdfService::class);

        // Сборка ушла в очередь (H3095): на пути запроса PDF ещё нет.
        $this->assertFalse($pdf->exists($submission));
        Queue::assertPushed(BuildHomeworkImagesPdfJob::class, fn (BuildHomeworkImagesPdfJob $job) => $job->submissionId === (int) $submission->id);

        $this->assertSame(1, $this->runQueuedImagesPdfJobs());

        $this->assertTrue($pdf->exists($submission));
        $path = $pdf->pathFor($submission);
        Storage::disk('local')->assertExists($path);
        $this->assertGreaterThan(100, Storage::disk('local')->size($path));
        $head = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF', $head);

        // Флаг вложения ленивый: считается на воркере, когда PDF уже собран.
        Mail::assertQueued(HomeworkSubmittedMail::class, function (HomeworkSubmittedMail $m) use ($submission) {
            return (int) $m->submission->id === (int) $submission->id
                && $m->hasImagesPdfAttachment() === true
                && count($m->attachments()) === 1;
        });
    }

    /** @test */
    public function submit_without_images_does_not_build_pdf(): void
    {
        [$course, $lesson] = $this->makeLessonWithHomework();
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Только текст',
                'files' => [
                    UploadedFile::fake()->create('work.pdf', 80, 'application/pdf'),
                ],
            ]
        )->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)->first();
        $this->runQueuedImagesPdfJobs();
        $this->assertFalse(app(HomeworkImagePdfService::class)->exists($submission));

        Mail::assertQueued(HomeworkSubmittedMail::class, function (HomeworkSubmittedMail $m) {
            return $m->hasImagesPdfAttachment() === false
                && count($m->attachments()) === 0;
        });
    }

    /** @test */
    public function staff_can_download_images_pdf_student_stranger_cannot(): void
    {
        [$course, $lesson, $teacher] = $this->makeLessonWithHomework();
        $student = User::factory()->create();
        $stranger = User::factory()->create();
        $teacherUser = User::where('teacher_id', $teacher->id)->first();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => null,
                'files' => [UploadedFile::fake()->image('a.jpg', 100, 80)],
            ]
        )->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)->first();
        $this->assertNotNull($submission);

        $inline = $this->actingAs($teacherUser)
            ->get(route('homework.submission.images-pdf', $submission));
        $inline->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'inline',
            strtolower((string) $inline->headers->get('content-disposition')),
        );

        $asFile = $this->actingAs($teacherUser)
            ->get(route('homework.submission.images-pdf', [
                'submission' => $submission,
                'download' => 1,
            ]));
        $asFile->assertOk();
        $this->assertStringContainsString(
            'attachment',
            strtolower((string) $asFile->headers->get('content-disposition')),
        );

        $this->actingAs($student)
            ->get(route('homework.submission.images-pdf', $submission))
            ->assertOk();

        $this->actingAs($stranger)
            ->get(route('homework.submission.images-pdf', $submission))
            ->assertForbidden();
    }

    /** @test */
    public function draft_does_not_build_pdf(): void
    {
        [$course, $lesson] = $this->makeLessonWithHomework();
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'draft',
                'body' => 'черновик',
                'files' => [UploadedFile::fake()->image('draft.jpg', 80, 60)],
            ]
        )->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)->first();
        $this->assertSame(HomeworkSubmission::STATUS_DRAFT, $submission->status);
        Queue::assertNotPushed(BuildHomeworkImagesPdfJob::class);
        $this->assertFalse(app(HomeworkImagePdfService::class)->exists($submission));
    }

    /**
     * Регрессия: фото 4032×3024 с телефона уходило в dompdf в исходном
     * размере, и сборка PDF валила php-fpm по памяти — вместе с POST сдачи
     * (гр.60, «Кочергина 3 (читка)», 18.08.2026).
     *
     * @test
     */
    public function full_size_phone_photo_is_downscaled_before_embedding(): void
    {
        config(['homework.image_pdf.max_edge_px' => 800]);

        $big = imagecreatetruecolor(4032, 3024);
        ob_start();
        imagejpeg($big, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($big);

        $method = new \ReflectionMethod(HomeworkImagePdfService::class, 'normalizeToDompdfImage');
        $method->setAccessible(true);

        $converted = $method->invoke(app(HomeworkImagePdfService::class), $bytes, 'image/jpeg');

        $this->assertIsArray($converted);
        $this->assertSame('image/jpeg', $converted['mime']);

        [$width, $height] = getimagesizefromstring($converted['bytes']);
        $this->assertLessThanOrEqual(800, max($width, $height));
        $this->assertLessThan(strlen($bytes), strlen($converted['bytes']));
    }

    /**
     * Кадр, который не читают ни imagick, ни GD, больше не вклеивается
     * в исходном виде, если он крупный: именно этот путь и раздувал сборку.
     *
     * @test
     */
    public function unreadable_oversized_frame_is_skipped_not_embedded_raw(): void
    {
        config(['homework.image_pdf.raw_passthrough_max_kb' => 1]);

        $method = new \ReflectionMethod(HomeworkImagePdfService::class, 'normalizeToDompdfImage');
        $method->setAccessible(true);

        $garbage = str_repeat('x', 200 * 1024);

        $this->assertNull(
            $method->invoke(app(HomeworkImagePdfService::class), $garbage, 'image/heic'),
        );
    }

    /** @test */
    public function pdf_page_count_is_capped_but_submission_keeps_every_file(): void
    {
        config(['homework.image_pdf.max_pages' => 2]);

        [$course, $lesson] = $this->makeLessonWithHomework();
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => null,
                'files' => [
                    UploadedFile::fake()->image('p1.jpg', 120, 90),
                    UploadedFile::fake()->image('p2.jpg', 120, 90),
                    UploadedFile::fake()->image('p3.jpg', 120, 90),
                    UploadedFile::fake()->image('p4.jpg', 120, 90),
                ],
            ]
        )->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)->first();
        $this->assertNotNull($submission);

        $this->runQueuedImagesPdfJobs();

        $pdf = app(HomeworkImagePdfService::class);
        $this->assertTrue($pdf->exists($submission));

        // Потолок режет только сборку — сами файлы работы остаются на месте.
        $this->assertCount(4, $pdf->studentImageFiles($submission));
    }
}
