<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GC-B3 / H601: `meeting_*` — сгенерированные (GENERATED ALWAYS AS ... VIRTUAL)
 * алиасы поверх `zoom_*` на schedules, часть WebinarProvider-шва. Значение
 * читается из БД, не из PHP-слоя — тест проверяет реальный SQL-уровень.
 */
class ScheduleMeetingAliasColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function meeting_columns_mirror_zoom_columns(): void
    {
        $course = Course::factory()->create();
        $schedule = Schedule::create([
            'title' => 'Занятие', 'course_id' => $course->id,
            'start' => now(), 'end' => now()->addHours(2),
            'zoom_meeting_id' => '123456789',
            'zoom_join_url' => 'https://zoom.us/j/123456789',
            'zoom_start_url' => 'https://zoom.us/s/123456789',
            'zoom_recording_url' => 'https://zoom.us/rec/123456789',
        ]);

        $fresh = Schedule::query()->findOrFail($schedule->id);

        $this->assertSame('123456789', $fresh->meeting_id);
        $this->assertSame('https://zoom.us/j/123456789', $fresh->meeting_join_url);
        $this->assertSame('https://zoom.us/s/123456789', $fresh->meeting_start_url);
        $this->assertSame('https://zoom.us/rec/123456789', $fresh->meeting_recording_url);
    }

    /** @test */
    public function meeting_columns_are_null_when_zoom_columns_are_null(): void
    {
        $course = Course::factory()->create();
        $schedule = Schedule::create([
            'title' => 'Занятие', 'course_id' => $course->id,
            'start' => now(), 'end' => now()->addHours(2),
        ]);

        $fresh = Schedule::query()->findOrFail($schedule->id);

        $this->assertNull($fresh->meeting_id);
        $this->assertNull($fresh->meeting_join_url);
    }
}
