<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Mail\HomeworkReviewedMail;
use App\Mail\HomeworkSubmittedMail;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
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

class HomeworkFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
    }

    private function makeTeacher(string $email = 'teacher@example.test'): array
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => $email]);
        $teacherUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);

        return [$teacher, $teacherUser];
    }

    private function makeLessonWithHomework(Teacher $teacher, bool $free = true): array
    {
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сделайте упражнение',
            'is_free' => $free,
            'block_number' => 2,
        ]);

        return [$course, $lesson];
    }

    /** @test */
    public function student_submits_homework_and_teacher_is_notified(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Моё решение',
                'files' => [UploadedFile::fake()->create('work.pdf', 120, 'application/pdf')],
            ]
        );

        $response->assertRedirect();

        $submission = HomeworkSubmission::where('user_id', $student->id)->where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($submission);
        $this->assertSame(HomeworkSubmission::STATUS_SUBMITTED, $submission->status);
        $this->assertCount(1, $submission->comments);
        $comment = $submission->comments->first();
        $this->assertSame('submission', $comment->type);
        $this->assertCount(1, $comment->files);
        Storage::disk('local')->assertExists($comment->files->first()->path);

        Mail::assertQueued(HomeworkSubmittedMail::class, function ($m) use ($course, $student) {
            $subject = $m->envelope()->subject;

            return $m->hasTo('teacher@example.test')
                && $m->isResubmission === false
                && str_starts_with($subject, '📝')
                && str_contains($subject, $course->title)
                && str_contains($subject, $student->name);
        });
    }

    /** @test */
    public function student_without_access_cannot_submit(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher, free: false);
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'x']
        )->assertForbidden();

        $this->assertDatabaseCount('homework_submissions', 0);
    }

    /** @test */
    public function paid_student_without_free_lesson_can_submit(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher, free: false);
        $student = User::factory()->create();

        Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'решение']
        )->assertRedirect();

        $this->assertDatabaseHas('homework_submissions', [
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
        ]);
    }

    /** @test */
    public function student_with_lesson_grant_can_submit_homework(): void
    {
        // Регрессия (money-core, H071 #16): гейт ДЗ проверял только is_free и
        // оплаченные тарифные ключи, игнорируя LessonAccessGrant. Держатель
        // персонального гранта (платное пробное / выданный куратором урок) мог
        // смотреть урок, но получал 403 на сдаче ДЗ.
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher, free: false);
        $student = User::factory()->create();

        LessonAccessGrant::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
        ]);

        // Никаких оплаченных тарифных ключей — доступ только по гранту.
        $this->assertTrue(LessonAccessGrant::userCanWatch($student, $lesson));

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'решение по гранту']
        )->assertRedirect();

        $this->assertDatabaseHas('homework_submissions', [
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
        ]);
    }

    /** @test */
    public function review_cycle_return_resubmit_accept(): void
    {
        [$teacher, $teacherUser] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();
        $service = app(HomeworkService::class);

        // Сдача
        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'v1']
        );
        $submission = HomeworkSubmission::firstOrFail();

        // Вернуть на доработку
        $service->recordReview($submission, $teacherUser, HomeworkSubmission::STATUS_NEEDS_REVISION, 'Поправьте пункт 2');
        $this->assertSame(HomeworkSubmission::STATUS_NEEDS_REVISION, $submission->fresh()->status);
        Mail::assertQueued(HomeworkReviewedMail::class, fn ($m) => $m->hasTo($student->email));

        // Пересдача студентом
        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'v2 исправлено']
        )->assertRedirect();
        $this->assertSame(HomeworkSubmission::STATUS_SUBMITTED, $submission->fresh()->status);

        // Письмо учителю о пересдаче помечено как доработка и тема начинается с 🔁
        Mail::assertQueued(HomeworkSubmittedMail::class, function ($m) {
            return $m->isResubmission === true
                && str_starts_with($m->envelope()->subject, '🔁');
        });

        // Принять
        $service->recordReview($submission->fresh(), $teacherUser, HomeworkSubmission::STATUS_ACCEPTED, 'Отлично');
        $this->assertSame(HomeworkSubmission::STATUS_ACCEPTED, $submission->fresh()->status);
    }

    /** @test */
    public function submitted_work_cannot_be_edited_by_student(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'v1']
        );

        // Повторная попытка, пока статус submitted → отклоняется (с ошибкой), без новой записи.
        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'v2']
        )->assertSessionHas('error');

        $this->assertCount(1, HomeworkSubmission::firstOrFail()->comments);
    }

    /** @test */
    public function file_download_authorization(): void
    {
        [$teacher, $teacherUser] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'files' => [UploadedFile::fake()->create('w.pdf', 50, 'application/pdf')]]
        );

        $file = HomeworkSubmission::firstOrFail()->comments->first()->files->first();
        $url = route('homework.file.download', $file);

        $this->actingAs($student)->get($url)->assertOk();      // владелец
        $this->actingAs($teacherUser)->get($url)->assertOk();  // препод курса
        $this->actingAs($stranger)->get($url)->assertForbidden(); // посторонний
    }

    /** @test */
    public function resource_query_is_scoped_to_teacher_and_hides_drafts(): void
    {
        [$teacherA, $teacherAUser] = $this->makeTeacher('a@example.test');
        [$teacherB] = $this->makeTeacher('b@example.test');

        [$courseA, $lessonA] = $this->makeLessonWithHomework($teacherA);
        [$courseB, $lessonB] = $this->makeLessonWithHomework($teacherB);

        $s1 = User::factory()->create();
        $s2 = User::factory()->create();

        // Сдача на курс A
        $this->actingAs($s1)->post(route('student.homework.store', [$courseA->slug, $lessonA->id]), ['action' => 'submit', 'body' => 'a']);
        // Сдача на курс B
        $this->actingAs($s2)->post(route('student.homework.store', [$courseB->slug, $lessonB->id]), ['action' => 'submit', 'body' => 'b']);
        // Черновик на курс A (не должен попасть в выборку)
        $this->actingAs($s2)->post(route('student.homework.store', [$courseA->slug, $lessonA->id]), ['action' => 'draft', 'body' => 'draft']);

        $this->actingAs($teacherAUser);
        $ids = HomeworkSubmissionResource::getEloquentQuery()->pluck('course_id')->unique()->values();

        $this->assertTrue($ids->contains($courseA->id));
        $this->assertFalse($ids->contains($courseB->id), 'Препод A не должен видеть сдачи курса препода B.');
        $this->assertSame(1, HomeworkSubmissionResource::getEloquentQuery()->count(), 'Черновик не должен попадать в выборку.');
    }

    /** @test */
    public function student_can_attach_video_to_homework(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Ответ видеозаписью',
                'files' => [UploadedFile::fake()->create('answer.mp4', 4096, 'video/mp4')],
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $submission = HomeworkSubmission::where('user_id', $student->id)->where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($submission);
        $file = $submission->comments->first()->files->first();
        $this->assertSame('answer.mp4', $file->original_name);
        Storage::disk('local')->assertExists($file->path);
    }

    /** @test */
    public function student_can_mix_photo_audio_and_video_in_one_submission(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Всё сразу',
                'files' => [
                    UploadedFile::fake()->image('photo.jpg'),
                    UploadedFile::fake()->create('voice.mp3', 512, 'audio/mpeg'),
                    UploadedFile::fake()->create('answer.mov', 2048, 'video/quicktime'),
                ],
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $submission = HomeworkSubmission::where('user_id', $student->id)->where('lesson_id', $lesson->id)->first();
        $this->assertCount(3, $submission->comments->first()->files);
    }

    /** @test */
    public function executable_files_are_still_rejected(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Попытка',
                'files' => [UploadedFile::fake()->create('payload.php', 8, 'application/x-php')],
            ]
        );

        $response->assertSessionHasErrors('files.0');
        $this->assertNull(HomeworkSubmission::where('user_id', $student->id)->first());
    }

    /** @test */
    public function submission_over_the_total_size_cap_is_rejected_with_a_readable_error(): void
    {
        config(['homework.total_max_kb' => 5120]); // 5 МБ на всю отправку

        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            [
                'action' => 'submit',
                'body' => 'Три тяжёлых видео',
                'files' => [
                    UploadedFile::fake()->create('a.mp4', 2048, 'video/mp4'),
                    UploadedFile::fake()->create('b.mp4', 2048, 'video/mp4'),
                    UploadedFile::fake()->create('c.mp4', 2048, 'video/mp4'),
                ],
            ]
        );

        // Каждый файл по отдельности в лимит укладывается — ловится именно сумма.
        $response->assertSessionHasErrors('files');
        $this->assertStringContainsString(
            'Суммарный размер файлов',
            session('errors')->first('files')
        );
        $this->assertNull(HomeworkSubmission::where('user_id', $student->id)->first());
    }
}
