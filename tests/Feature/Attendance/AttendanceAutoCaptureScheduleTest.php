<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H8494 — регистрация автосбора посещаемости в планировщике: существующая
 * W3-машина (attendance:backfill-streams) в режиме --scheduled --apply,
 * ежедневно после завершившегося дня занятий.
 */
class AttendanceAutoCaptureScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_capture_entry_is_registered_daily(): void
    {
        $event = $this->eventFor('attendance:backfill-streams');

        $this->assertNotNull($event, 'attendance:backfill-streams not in Kernel schedule');
        // dailyAt('23:40') → «40 23 * * *»
        $this->assertSame('40 23 * * *', $event->expression);
        $this->assertStringContainsString('--scheduled', $event->command);
        $this->assertStringContainsString('--apply', $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    private function eventFor(string $needle): ?Event
    {
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
