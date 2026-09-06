<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\AccessDiagnosticsService;
use App\Services\Support\SupportAnswerFactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H3999 (рулинг A1): резолвер состояния доступа.
 *
 * Второй логики доступа не заводим: открытость урока считает
 * {@see AccessDiagnosticsService::isLessonAccessible()} — тот же
 * код, что решает, пустить ли студента на урок в кабинете. Тест проверяет, что
 * черновик называет ЭТИ числа и что политика — только черновик.
 */
class SupportFactResolverAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: User, 1: Course, 2: Group} */
    private function enrolledStudent(array $blockNumbers = [1, 2]): array
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля', 'is_active' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        foreach ($blockNumbers as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);

            Lesson::factory()->create([
                'course_id' => $course->id,
                'block_number' => $n,
                'is_published' => true,
                'is_preview' => false,
                'is_free' => false,
                'group_id' => null,
            ]);
        }

        return [$student, $course, $group];
    }

    private function resolve(User $student): ?array
    {
        return app(SupportAnswerFactResolver::class)->resolve(
            SupportAnswerSuggestion::CATEGORY_ACCESS,
            $student,
            'почему урок закрыт?',
        );
    }

    public function test_without_any_paid_key_nothing_is_open(): void
    {
        [$student] = $this->enrolledStudent();

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('открыто 0 из 2 опубликованных уроков', $resolved['draft']);
        $this->assertStringContainsString('оплаченных тарифов по курсу в кабинете нет', $resolved['draft']);
        $this->assertSame(SupportAnswerFactResolver::TYPE_ACCESS, $resolved['facts']['type']);
        $this->assertSame(0, $resolved['facts']['courses'][0]['open_lessons']);
        $this->assertSame(2, $resolved['facts']['courses'][0]['published_lessons']);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
    }

    public function test_one_paid_block_opens_exactly_its_own_lessons(): void
    {
        [$student, $course] = $this->enrolledStudent();

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 1,
            'is_conditional' => false,
        ]));

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('открыто 1 из 2 опубликованных уроков', $resolved['draft']);
        $this->assertSame(['block_1'], $resolved['facts']['courses'][0]['owned_keys']);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
    }

    public function test_course_without_published_lessons_says_so_instead_of_zero_of_zero(): void
    {
        [$student] = $this->enrolledStudent([]);

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('опубликованных уроков пока нет', $resolved['draft']);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
    }

    public function test_student_outside_any_active_group_gets_no_draft(): void
    {
        $student = User::factory()->create();

        $this->assertNull(
            $this->resolve($student),
            'Не зачислен — «доступа нет» было бы выдуманным ответом, черновика нет вовсе.',
        );
    }

    public function test_leaving_the_group_removes_the_course_from_the_draft(): void
    {
        [$student, $course, $group] = $this->enrolledStudent();

        // Выведенная группа не «активная»: у выпускника черновик не должен
        // рассказывать про доступ к курсу, из которого он вышел.
        $student->groups()->updateExistingPivot($group->id, ['left_at' => now()->subDay()]);
        $student->refresh();

        $this->assertNull($this->resolve($student));
    }
}
