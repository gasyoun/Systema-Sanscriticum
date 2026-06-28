<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Резолв занятия по Zoom-событию при единой ссылке курса: один meeting_id на все
 * даты разруливается по occurrence uuid + времени запуска.
 */
class ScheduleZoomResolveTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function picks_schedule_whose_window_contains_event_time_and_binds_uuid(): void
    {
        $course = Course::factory()->create(['zoom_meeting_id' => '900900900']);
        $today = Schedule::create([
            'title' => 'Занятие сегодня', 'course_id' => $course->id,
            'start' => now()->setTime(10, 0), 'end' => now()->setTime(12, 0),
        ]);
        $nextWeek = Schedule::create([
            'title' => 'Занятие через неделю', 'course_id' => $course->id,
            'start' => now()->addWeek()->setTime(10, 0), 'end' => now()->addWeek()->setTime(12, 0),
        ]);

        $resolved = Schedule::resolveForZoomEvent('900900900', 'occ-1', now()->setTime(10, 5)->toIso8601String());

        $this->assertSame($today->id, $resolved->id);
        $this->assertSame('occ-1', $today->fresh()->zoom_occurrence_uuid);
        $this->assertNull($nextWeek->fresh()->zoom_occurrence_uuid);
    }

    /** @test */
    public function bound_occurrence_uuid_wins_over_time(): void
    {
        $course = Course::factory()->create(['zoom_meeting_id' => '111']);
        $bound = Schedule::create([
            'title' => 'Привязанное', 'course_id' => $course->id,
            'start' => now()->setTime(10, 0), 'zoom_occurrence_uuid' => 'occ-bound',
        ]);
        Schedule::create([
            'title' => 'Другое время', 'course_id' => $course->id,
            'start' => now()->addWeek()->setTime(10, 0),
        ]);

        // Время указывает на следующую неделю, но uuid уже привязан к $bound.
        $resolved = Schedule::resolveForZoomEvent('111', 'occ-bound', now()->addWeek()->setTime(10, 0)->toIso8601String());

        $this->assertSame($bound->id, $resolved->id);
    }

    /** @test */
    public function legacy_fallback_resolves_by_schedule_meeting_id(): void
    {
        // Старая ручная встреча: meeting_id на самом занятии, курса с таким id нет.
        $schedule = Schedule::create([
            'title' => 'Ручная', 'start' => now(), 'zoom_meeting_id' => '777',
        ]);

        $resolved = Schedule::resolveForZoomEvent('777', null, now()->toIso8601String());

        $this->assertSame($schedule->id, $resolved->id);
    }

    /** @test */
    public function unknown_meeting_returns_null(): void
    {
        $this->assertNull(Schedule::resolveForZoomEvent('does-not-exist', 'occ', now()->toIso8601String()));
    }
}
