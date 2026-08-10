<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Фид отдаёт только НЕистёкшие занятия (IcsFeedBuilder: `end >= now()`),
     * поэтому фикстуры с абсолютными датами обязаны пиниться к фиксированному
     * «сейчас» — иначе тест зелёный до 12:00Z того же дня и красный после
     * (ровно это и случилось 10-08-2026, H2541).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function studentInGroup(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Поток А']);
        $user->groups()->attach($group->id);

        return [$user, $group];
    }

    /** @test */
    public function feed_returns_valid_vcalendar_with_schedule_events_in_utc(): void
    {
        [$user, $group] = $this->studentInGroup();

        // MSK — UTC+3, so 15:00 MSK must serialize as 12:00Z.
        Schedule::create([
            'title' => 'Занятие по грамматике',
            'zoom_join_url' => 'https://zoom.us/j/123456',
            'start' => Carbon::parse('2026-08-10 15:00:00', 'Europe/Moscow'),
            'end' => Carbon::parse('2026-08-10 16:00:00', 'Europe/Moscow'),
            'group_id' => $group->id,
        ]);

        $token = $user->calendarFeedToken()->token;

        $response = $this->get(route('student.calendar.feed', ['user' => $user->id, 'token' => $token]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        $body = $response->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('END:VCALENDAR', $body);
        $this->assertStringContainsString('SUMMARY:Занятие по грамматике', $body);
        $this->assertStringContainsString('DTSTART:20260810T120000Z', $body);
        $this->assertStringContainsString('DTEND:20260810T130000Z', $body);
        $this->assertStringContainsString('zoom.us/j/123456', $body);
    }

    /** @test */
    public function feed_includes_course_block_date_ranges_as_all_day_events(): void
    {
        [$user, $group] = $this->studentInGroup();
        $course = Course::factory()->create(['title' => 'Санскрит для начинающих']);
        $group->courses()->attach($course->id);

        CourseBlock::factory()->for($course)->withDates(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        )->create(['title' => 'Блок 1']);

        $token = $user->calendarFeedToken()->token;

        $response = $this->get(route('student.calendar.feed', ['user' => $user->id, 'token' => $token]));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260901', $body);
        // DTEND is exclusive: day AFTER the block's last day.
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261001', $body);
        $this->assertStringContainsString('Санскрит для начинающих', $body);
    }

    /** @test */
    public function revoked_token_returns_404(): void
    {
        [$user] = $this->studentInGroup();
        $feedToken = $user->calendarFeedToken();
        $feedToken->update(['revoked_at' => now()]);

        $response = $this->get(route('student.calendar.feed', ['user' => $user->id, 'token' => $feedToken->token]));

        $response->assertNotFound();
    }

    /** @test */
    public function wrong_token_returns_404(): void
    {
        [$user] = $this->studentInGroup();
        $user->calendarFeedToken();

        $response = $this->get(route('student.calendar.feed', ['user' => $user->id, 'token' => 'not-the-real-token']));

        $response->assertNotFound();
    }

    /** @test */
    public function regenerate_revokes_old_token_and_issues_a_new_one(): void
    {
        [$user] = $this->studentInGroup();
        $oldToken = $user->calendarFeedToken()->token;

        $this->actingAs($user)
            ->post(route('student.calendar.feed.regenerate'))
            ->assertRedirect();

        $newToken = $user->fresh()->calendarFeedToken()->token;

        $this->assertNotEquals($oldToken, $newToken);

        $this->get(route('student.calendar.feed', ['user' => $user->id, 'token' => $oldToken]))
            ->assertNotFound();

        $this->get(route('student.calendar.feed', ['user' => $user->id, 'token' => $newToken]))
            ->assertOk();
    }
}
