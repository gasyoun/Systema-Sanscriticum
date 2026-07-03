<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Jobs\SendHomeworkNotificationJob;
use App\Mail\HomeworkReviewedMail;
use App\Mail\HomeworkSubmittedMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkDeadlineOverride;
use App\Models\HomeworkNotificationLog;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
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

        \App\Models\LessonAccessGrant::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
        ]);

        // Никаких оплаченных тарифных ключей — доступ только по гранту.
        $this->assertTrue(\App\Models\LessonAccessGrant::userCanWatch($student, $lesson));

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
        $service->recordReview($submission, $teacherUser, HomeworkSubmission::STATUS_NEEDS_CHANGES, 'Поправьте пункт 2');
        $this->assertSame(HomeworkSubmission::STATUS_NEEDS_CHANGES, $submission->fresh()->status);
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
        $service->recordReview($submission->fresh(), $teacherUser, HomeworkSubmission::STATUS_ACCEPTED, 'Отлично', [], HomeworkSubmission::GRADE_5_PLUS);
        $this->assertSame(HomeworkSubmission::STATUS_ACCEPTED, $submission->fresh()->status);
        $this->assertSame(HomeworkSubmission::GRADE_5_PLUS, $submission->fresh()->grade);
    }

    /** @test */
    public function accepted_homework_requires_grade(): void
    {
        [$teacher, $teacherUser] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();
        $submission = HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(HomeworkService::class)->recordReview($submission, $teacherUser, HomeworkSubmission::STATUS_ACCEPTED, 'ok');
    }

    /** @test */
    public function lesson_default_deadline_blocks_submission_after_lock_time(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();
        $lesson->update([
            'homework_due_at' => now()->subDays(2),
            'homework_locks_at' => now()->subDay(),
        ]);

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'late']
        )->assertSessionHas('error');

        $this->assertDatabaseCount('homework_submissions', 0);
    }

    /** @test */
    public function student_deadline_override_beats_group_and_lesson_deadlines(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->syncWithoutDetaching([$group->id]);
        $student->groups()->syncWithoutDetaching([$group->id]);
        $lesson->update(['homework_due_at' => now()->subDays(3)]);
        HomeworkDeadlineOverride::create([
            'lesson_id' => $lesson->id,
            'group_id' => $group->id,
            'due_at' => now()->subDays(2),
            'locks_at' => now()->subDay(),
        ]);
        HomeworkDeadlineOverride::create([
            'lesson_id' => $lesson->id,
            'user_id' => $student->id,
            'due_at' => now()->addDay(),
            'locks_at' => now()->addDays(2),
        ]);

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'override ok']
        )->assertRedirect();

        $this->assertDatabaseHas('homework_submissions', [
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
        ]);
    }

    /** @test */
    public function group_deadline_override_beats_lesson_deadline(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();
        $group = Group::create(['name' => 'G2']);
        $course->groups()->syncWithoutDetaching([$group->id]);
        $student->groups()->syncWithoutDetaching([$group->id]);
        $lesson->update(['homework_due_at' => now()->subDays(3), 'homework_locks_at' => now()->subDay()]);
        HomeworkDeadlineOverride::create([
            'lesson_id' => $lesson->id,
            'group_id' => $group->id,
            'due_at' => now()->addDay(),
            'locks_at' => now()->addDays(2),
        ]);

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'group override ok']
        )->assertRedirect();

        $this->assertDatabaseHas('homework_submissions', [
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    /** @test */
    public function student_submission_queues_homework_notification_job(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lesson->id]),
            ['action' => 'submit', 'body' => 'notify']
        )->assertRedirect();

        Queue::assertPushed(SendHomeworkNotificationJob::class);
    }

    /** @test */
    public function deadline_command_deduplicates_student_notifications(): void
    {
        [$teacher] = $this->makeTeacher();
        [$course, $lesson] = $this->makeLessonWithHomework($teacher);
        $student = User::factory()->create();
        $group = Group::create(['name' => 'Deadline group']);
        $course->groups()->syncWithoutDetaching([$group->id]);
        $student->groups()->syncWithoutDetaching([$group->id]);
        $lesson->update([
            'homework_opens_at' => now()->subHour(),
            'homework_due_at' => now()->addHours(12),
        ]);

        $this->artisan('homework:notify-deadlines')->assertSuccessful();
        $this->artisan('homework:notify-deadlines')->assertSuccessful();

        $this->assertSame(1, HomeworkNotificationLog::where('event', \App\Services\HomeworkNotificationService::EVENT_OPENED)->count());
        $this->assertSame(1, HomeworkNotificationLog::where('event', \App\Services\HomeworkNotificationService::EVENT_DEADLINE_NEAR)->count());
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
}
