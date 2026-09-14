<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Support\SupportAnswerFactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H3999: категория C распадается на «когда занятие» и «что изменилось».
 *
 * Вид изменения определяется ПО ТАБЛИЦЕ (created_at / updated_at / deleted_at
 * относительно окна), а не по догадке. Прежнее время занятия нигде не хранится,
 * поэтому «перенесено» черновик не утверждает — он говорит «изменилось, сейчас
 * в расписании такое-то время», что верно и для переноса, и для правки ссылки.
 */
class SupportFactResolverScheduleChangeTest extends TestCase
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

    /** @return array{0: User, 1: Group, 2: Course} */
    private function enrolledStudent(): array
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        return [$student, $group, $course];
    }

    /** Занятие с явно проставленными created_at/updated_at — вид зависит от них. */
    private function classIn(Group $group, Course $course, string $title, string $start, string $createdAt, ?string $updatedAt = null): Schedule
    {
        $class = Schedule::create([
            'title' => $title,
            'group_id' => $group->id,
            'course_id' => $course->id,
            'start' => $start,
            'end' => Carbon::parse($start)->addHours(2),
            'link' => 'https://zoom.us/j/1',
        ]);

        $class->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt ?? $createdAt,
        ])->saveQuietly();

        return $class->refresh();
    }

    private function resolve(User $student, string $text = 'занятие перенесли?'): ?array
    {
        return app(SupportAnswerFactResolver::class)->resolve(
            SupportAnswerSuggestion::CATEGORY_SCHEDULE,
            $student,
            $text,
        );
    }

    public function test_a_class_created_inside_the_window_reads_as_added(): void
    {
        [$student, $group, $course] = $this->enrolledStudent();
        $this->classIn($group, $course, 'Доп. занятие', '2026-09-10 19:00:00', '2026-09-04 09:00:00');

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertSame(SupportAnswerFactResolver::TYPE_SCHEDULE_CHANGE, $resolved['facts']['type']);
        $this->assertSame('added', $resolved['facts']['changes'][0]['kind']);
        $this->assertStringContainsString('добавлено занятие «Доп. занятие»', $resolved['draft']);
    }

    public function test_an_old_class_updated_inside_the_window_reads_as_changed_not_moved(): void
    {
        [$student, $group, $course] = $this->enrolledStudent();
        $this->classIn($group, $course, 'Урок 7', '2026-09-12 19:00:00', '2026-07-01 09:00:00', '2026-09-05 10:00:00');

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertSame('moved', $resolved['facts']['changes'][0]['kind']);
        $this->assertStringContainsString('занятие «Урок 7» изменилось — сейчас в расписании', $resolved['draft']);
        $this->assertStringNotContainsString('перенесено', $resolved['draft']);
    }

    public function test_a_deleted_class_reads_as_cancelled(): void
    {
        [$student, $group, $course] = $this->enrolledStudent();
        $class = $this->classIn($group, $course, 'Урок 8', '2026-09-14 19:00:00', '2026-07-01 09:00:00');
        $class->delete();

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertSame('cancelled', $resolved['facts']['changes'][0]['kind']);
        $this->assertStringContainsString('отменено', $resolved['draft']);
    }

    public function test_an_untouched_class_is_not_a_change_and_falls_back_to_the_schedule_answer(): void
    {
        [$student, $group, $course] = $this->enrolledStudent();
        $this->classIn($group, $course, 'Урок 9', '2026-09-16 19:00:00', '2026-07-01 09:00:00');

        $resolved = $this->resolve($student);

        // Изменений нет — вопрос про перенос откатывается на обычное расписание,
        // а не выдумывает изменение, которого в таблице нет.
        $this->assertNotNull($resolved);
        $this->assertSame(SupportAnswerFactResolver::TYPE_SCHEDULE, $resolved['facts']['type']);
    }

    public function test_a_plain_schedule_question_never_reaches_the_change_arm(): void
    {
        [$student, $group, $course] = $this->enrolledStudent();
        $this->classIn($group, $course, 'Доп. занятие', '2026-09-10 19:00:00', '2026-09-04 09:00:00');

        $resolved = $this->resolve($student, 'когда следующее занятие?');

        $this->assertNotNull($resolved);
        $this->assertSame(SupportAnswerFactResolver::TYPE_SCHEDULE, $resolved['facts']['type']);
    }

    public function test_past_classes_are_not_reported_as_changes(): void
    {
        [$student, $group, $course] = $this->enrolledStudent();
        // Занятие уже прошло: новость про него студенту не нужна.
        $this->classIn($group, $course, 'Урок 1', '2026-09-01 19:00:00', '2026-09-04 09:00:00');

        $resolved = $this->resolve($student);

        $this->assertTrue(
            $resolved === null || $resolved['facts']['type'] === SupportAnswerFactResolver::TYPE_SCHEDULE,
            'Прошедшее занятие изменением не считается.',
        );
    }
}
