<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * prana:decay должен быть в расписании (еженедельно, ночное окно). Команда сама
 * безопасна при выключенном decay, поэтому повешена всегда — включается флагом.
 */
class PranaDecayScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function decayEvent(): ?Event
    {
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'prana:decay')) {
                return $event;
            }
        }

        return null;
    }

    /** @test */
    public function prana_decay_is_registered_in_the_schedule(): void
    {
        $this->assertNotNull($this->decayEvent(), 'prana:decay не найден в расписании Kernel');
    }

    /** @test */
    public function prana_decay_runs_weekly_on_monday_night(): void
    {
        // weeklyOn(1, '04:00') → cron «минута час * * день_недели» = «0 4 * * 1».
        $this->assertSame('0 4 * * 1', $this->decayEvent()?->expression);
    }
}
