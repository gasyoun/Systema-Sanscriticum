<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Mail\HomeworkSubmittedMail;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Support\HomeworkAutoOpenScope;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Формат курса решает, что происходит с ДЗ (H3081, MG 18-08-2026):
 * «Айтарея без домашки, продленка с домашкой, но без проверки домашки от
 * преподавателя, как и напевный санскрит».
 */
class UnreviewedCourseHomeworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');

        config([
            'homework.reviewers.enabled' => true,
            'homework.reviewers.unreviewed_course_prefixes' => ['napevnyi-sanskrit-', 'prodlenka-'],
            'homework.auto_open.enabled' => true,
            'homework.auto_open.scope' => 'all',
            'homework.auto_open.min_course_start' => '',
            'homework.auto_open.policy_epoch' => '',
            'homework.auto_open.exclude_course_slugs' => [],
            'homework.auto_open.exclude_course_prefixes' => ['ctenie-'],
        ]);
    }

    // ===================================================================
    // Чтение — без приёма вовсе
    // ===================================================================

    /** @test */
    public function reading_course_is_excluded_from_auto_open_by_prefix(): void
    {
        $reading = Course::factory()->create(['slug' => 'ctenie-aitareii-2026']);
        $grammar = Course::factory()->create(['slug' => 'grammatika-po-kocerginoi-gr77']);

        $ids = HomeworkAutoOpenScope::courseIdsInScope();

        $this->assertNotContains((int) $reading->id, $ids, 'Курс чтения попал в охват приёма ДЗ.');
        $this->assertContains((int) $grammar->id, $ids, 'Обычный курс выпал из охвата.');
    }

    /** Правило про формат, а не про один поток: следующее чтение унаследует его. */
    /** @test */
    public function a_future_reading_course_inherits_the_exclusion(): void
    {
        $next = Course::factory()->create(['slug' => 'ctenie-kaushitaki-2027']);

        $this->assertNotContains((int) $next->id, HomeworkAutoOpenScope::courseIdsInScope());
    }

    // ===================================================================
    // Продлёнка и напевный — приём есть, проверки нет
    // ===================================================================

    /**
     * @test
     *
     * @dataProvider unreviewedSlugs
     */
    public function submission_on_an_unreviewed_course_notifies_nobody(string $slug): void
    {
        [$course, $lesson] = $this->courseWithHomework($slug);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('student.homework.store', [$course->slug, $lesson->id]), [
                'action' => 'submit',
                'body' => 'Моя работа',
            ])
            ->assertRedirect();

        // Работа принята и сохранена — «без проверки» не значит «без приёма».
        $submission = HomeworkSubmission::where('user_id', $student->id)->first();
        $this->assertNotNull($submission, 'Работа не сохранилась.');
        $this->assertSame(HomeworkSubmission::STATUS_SUBMITTED, $submission->status);

        // И при этом никого не дёрнули. Ключевой случай: у курса ЕСТЬ
        // преподаватель, и без правила ему ушло бы письмо на каждую работу —
        // пустой group_reviewer сам по себе тишины не даёт.
        Mail::assertNotQueued(HomeworkSubmittedMail::class);
        Mail::assertNothingSent();
    }

    /** @return array<string, array{0: string}> */
    public static function unreviewedSlugs(): array
    {
        return [
            'продлёнка санскрита' => ['prodlenka-sanskrita-2026'],
            'продлёнка хинди' => ['prodlenka-hindi-s-kostinoi-2026'],
            'напевный санскрит' => ['napevnyi-sanskrit-gimn-sutr-patandzali-vt-15-2026'],
        ];
    }

    /** Контроль: на обычном курсе письмо преподавателю по-прежнему уходит. */
    /** @test */
    public function submission_on_a_normal_course_still_notifies_the_teacher(): void
    {
        [$course, $lesson] = $this->courseWithHomework('grammatika-po-kocerginoi-gr78');
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('student.homework.store', [$course->slug, $lesson->id]), [
                'action' => 'submit',
                'body' => 'Моя работа',
            ])
            ->assertRedirect();

        Mail::assertQueued(HomeworkSubmittedMail::class);
    }

    /**
     * Студенту не обещают проверки, которой не будет: карточка урока говорит
     * «Сдано», а не «На проверке».
     */
    /** @test */
    public function student_sees_no_promise_of_review(): void
    {
        [$course, $lesson] = $this->courseWithHomework('napevnyi-sanskrit-gimn-kali-2027');
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('student.homework.store', [$course->slug, $lesson->id]), [
                'action' => 'submit',
                'body' => 'Моя работа',
            ]);

        $html = $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Сдано', $html);
        $this->assertStringNotContainsString('Работа у куратора', $html);
    }

    /** @return array{0: Course, 1: Lesson} */
    private function courseWithHomework(string $slug): array
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);

        $course = Course::factory()->create(['slug' => $slug, 'teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create([
            'homework_enabled' => true,
            'homework_prompt' => 'Сделайте упражнение',
            'is_free' => true,
            'block_number' => 2,
        ]);

        return [$course, $lesson];
    }
}
