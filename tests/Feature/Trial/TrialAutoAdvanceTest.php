<?php

declare(strict_types=1);

namespace Tests\Feature\Trial;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * features.trial_auto_advance (08-10-2026): пин пробного занятия сам
 * переходит на следующее занятие той же группы, когда текущее началось.
 * Прод: курс 348 закреплён за занятием 09.10 20:00 — после него кнопка
 * продавала бы запись, пока человек не переуказал бы занятие в Filament.
 */
class TrialAutoAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 20:30:00', 'Europe/Moscow'));
        config([
            'features.trial_auto_advance' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->course = Course::factory()->create(['is_visible' => true, 'title' => 'Избранные главы']);
        $this->group = Group::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lessonAt(string $start, ?int $groupId = null, bool $overview = false, ?Course $course = null): Schedule
    {
        return Schedule::create([
            'title' => 'Занятие '.$start,
            'course_id' => ($course ?? $this->course)->id,
            'group_id' => $groupId ?? $this->group->id,
            'start' => Carbon::parse($start, 'Europe/Moscow'),
            'is_overview' => $overview,
            'link' => 'https://zoom.example/'.md5($start),
        ]);
    }

    private function pin(Schedule $schedule, float $price = 750): void
    {
        $this->course->update(['trial_price' => $price, 'trial_schedule_id' => $schedule->id]);
        $this->course->refresh();
    }

    public function test_started_session_moves_to_the_next_one_of_the_same_group_with_a_placeholder_lesson(): void
    {
        $current = $this->lessonAt('2026-10-09 20:00');
        $otherGroup = Group::factory()->create();
        $this->lessonAt('2026-10-12 20:00', $otherGroup->id);   // раньше, но чужая группа
        $this->lessonAt('2026-10-14 20:00', overview: true);    // обзорное — не то
        $next = $this->lessonAt('2026-10-16 20:00');
        $this->lessonAt('2026-10-23 20:00');
        $this->pin($current);
        $oldLessonId = $this->course->trial_lesson_id;

        $this->artisan('trial:auto-advance')->assertSuccessful();

        $this->course->refresh();
        $this->assertSame($next->id, (int) $this->course->trial_schedule_id);
        $lesson = Lesson::findOrFail($this->course->trial_lesson_id);
        $this->assertNotSame($oldLessonId, $lesson->id);
        $this->assertSame('2026-10-16', $lesson->lesson_date->toDateString());
        $this->assertSame($this->group->id, (int) $lesson->group_id);
        $this->assertSame($lesson->id, $this->course->trialGrantTarget()?->id);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'telegram.org')
            && str_contains((string) ($req->data()['text'] ?? ''), 'Пробное переключено'));
    }

    public function test_upcoming_session_is_left_alone(): void
    {
        $upcoming = $this->lessonAt('2026-10-09 21:00');
        $this->lessonAt('2026-10-16 20:00');
        $this->pin($upcoming);

        $this->artisan('trial:auto-advance')->assertSuccessful();

        $this->assertSame($upcoming->id, (int) $this->course->fresh()->trial_schedule_id);
        Http::assertNothingSent();
    }

    public function test_no_next_session_keeps_the_pin_and_reports_it(): void
    {
        $current = $this->lessonAt('2026-10-09 20:00');
        $this->pin($current);

        $this->artisan('trial:auto-advance')
            ->expectsOutputToContain('в расписании группы нет следующего занятия')
            ->assertSuccessful();

        $this->assertSame($current->id, (int) $this->course->fresh()->trial_schedule_id);
    }

    public function test_dry_run_and_flag_off_change_nothing(): void
    {
        $current = $this->lessonAt('2026-10-09 20:00');
        $this->lessonAt('2026-10-16 20:00');
        $this->pin($current);

        $this->artisan('trial:auto-advance --dry-run')
            ->expectsOutputToContain('16.10.2026 20:00')
            ->assertSuccessful();
        $this->assertSame($current->id, (int) $this->course->fresh()->trial_schedule_id);

        config(['features.trial_auto_advance' => false]);
        $this->artisan('trial:auto-advance')
            ->expectsOutputToContain('выключен')
            ->assertSuccessful();
        $this->assertSame($current->id, (int) $this->course->fresh()->trial_schedule_id);
        Http::assertNothingSent();
    }

    public function test_course_without_trial_price_is_not_touched(): void
    {
        $current = $this->lessonAt('2026-10-09 20:00');
        $this->lessonAt('2026-10-16 20:00');
        $this->pin($current, price: 0);

        $this->artisan('trial:auto-advance')->assertSuccessful();

        $this->assertSame($current->id, (int) $this->course->fresh()->trial_schedule_id);
    }

    /** Купивший до переключения сохраняет доступ к своему уроку; новые покупки — на новый. */
    public function test_earlier_buyer_keeps_the_old_lesson_grant(): void
    {
        $current = $this->lessonAt('2026-10-09 20:00');
        $this->lessonAt('2026-10-16 20:00');
        $this->pin($current);
        $oldLessonId = (int) $this->course->trial_lesson_id;
        $buyer = User::factory()->create();
        LessonAccessGrant::create([
            'user_id' => $buyer->id, 'lesson_id' => $oldLessonId, 'course_id' => $this->course->id,
            'reason' => 'оплачено пробное занятие', 'granted_at' => now()->subDay(),
        ]);

        $this->artisan('trial:auto-advance')->assertSuccessful();

        $this->assertNotSame($oldLessonId, (int) $this->course->fresh()->trial_lesson_id);
        $this->assertTrue(LessonAccessGrant::where('user_id', $buyer->id)->where('lesson_id', $oldLessonId)->active()->exists());
    }
}
