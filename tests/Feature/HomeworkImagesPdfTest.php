<?php

declare(strict_types=1);

namespace Tests\Feature;

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
        $this->assertTrue($pdf->exists($submission));
        $path = $pdf->pathFor($submission);
        Storage::disk('local')->assertExists($path);
        $this->assertGreaterThan(100, Storage::disk('local')->size($path));
        $head = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF', $head);

        Mail::assertQueued(HomeworkSubmittedMail::class, function (HomeworkSubmittedMail $m) use ($submission) {
            return (int) $m->submission->id === (int) $submission->id
                && $m->hasImagesPdfAttachment === true
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
        $this->assertFalse(app(HomeworkImagePdfService::class)->exists($submission));

        Mail::assertQueued(HomeworkSubmittedMail::class, function (HomeworkSubmittedMail $m) {
            return $m->hasImagesPdfAttachment === false
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

        $this->actingAs($teacherUser)
            ->get(route('homework.submission.images-pdf', $submission))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

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
        $this->assertFalse(app(HomeworkImagePdfService::class)->exists($submission));
    }
}
