<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Support\SupportAnswerFactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H3999 (шаг I2): резолвер статуса домашних работ.
 *
 * Проверяем наблюдаемое, а не вызванное: КАКОЙ статус назван студенту в четырёх
 * состояниях работы и что при отсутствии работ черновика НЕТ вовсе — «работ не
 * найдено» было бы выдуманным ответом (студент мог сдавать не через кабинет).
 */
class SupportFactResolverHomeworkTest extends TestCase
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

    /** @return array{0: User, 1: Course, 2: Lesson} */
    private function enrolledStudent(): array
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $group = Group::factory()->create();
        $group->courses()->attach($course->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'title' => 'Урок 3',
            'is_published' => true,
        ]);

        return [$student, $course, $lesson];
    }

    public function test_no_submission_yields_no_draft(): void
    {
        [$student] = $this->enrolledStudent();

        $this->assertNull(
            app(SupportAnswerFactResolver::class)->resolve(
                SupportAnswerSuggestion::CATEGORY_MATERIALS,
                $student,
                'а моя домашка проверена?',
            ),
            'Работ нет — черновик не создаётся, а не сочиняется.',
        );
    }

    /**
     * @dataProvider statuses
     */
    public function test_each_status_is_named_in_the_draft(string $status, string $expected): void
    {
        [$student, $course, $lesson] = $this->enrolledStudent();

        HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => $status,
            'last_activity_at' => now()->subHour(),
        ]);

        $resolved = app(SupportAnswerFactResolver::class)->resolve(
            SupportAnswerSuggestion::CATEGORY_MATERIALS,
            $student,
            'что с домашним заданием',
        );

        $this->assertNotNull($resolved);
        $this->assertStringContainsString($expected, $resolved['draft']);
        $this->assertStringContainsString('Урок 3', $resolved['draft']);
        $this->assertSame(SupportAnswerFactResolver::TYPE_HOMEWORK, $resolved['facts']['type']);
        $this->assertSame($status, $resolved['facts']['latest_status']);

        // Статус ДЗ — не деньги и не доступ: он в принципе может стать живым
        // после недели тени, поэтому политика auto (открывает её человек).
        $this->assertSame(SupportAnswerFactResolver::POLICY_AUTO, $resolved['send_policy']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function statuses(): array
    {
        return [
            'черновик' => [HomeworkSubmission::STATUS_DRAFT, 'ещё не отправлена на проверку'],
            'отправлена' => [HomeworkSubmission::STATUS_SUBMITTED, 'отправлена на проверку'],
            'на доработке' => [HomeworkSubmission::STATUS_NEEDS_REVISION, 'возвращена на доработку'],
            'принята' => [HomeworkSubmission::STATUS_ACCEPTED, 'принята'],
        ];
    }

    public function test_latest_submission_wins_and_counts_are_reported(): void
    {
        [$student, $course, $lesson] = $this->enrolledStudent();

        // Схемный факт: (user_id, lesson_id) уникальна — одна работа на урок,
        // поэтому вторая работа живёт на втором уроке, а не на том же.
        $second = Lesson::factory()->create([
            'course_id' => $course->id,
            'title' => 'Урок 4',
            'is_published' => true,
        ]);

        HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_ACCEPTED,
            'last_activity_at' => now()->subDays(3),
        ]);

        HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $second->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $resolved = app(SupportAnswerFactResolver::class)->resolve(
            SupportAnswerSuggestion::CATEGORY_MATERIALS,
            $student,
            'домашка',
        );

        $this->assertNotNull($resolved);
        $this->assertSame(HomeworkSubmission::STATUS_NEEDS_REVISION, $resolved['facts']['latest_status']);
        $this->assertSame(2, $resolved['facts']['total']);
        $this->assertStringContainsString('всего работ в кабинете: 2', $resolved['draft']);
    }
}
