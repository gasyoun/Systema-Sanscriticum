<?php

namespace Tests\Concerns;

use App\Models\Schedule;

trait WithStaffedIntroSession
{
    private function confirmIntroSession(): Schedule
    {
        $schedule = Schedule::create([
            'title' => 'Confirmed beginner consultation',
            'start' => now()->addDays(2),
            'end' => now()->addDays(2)->addHour(),
        ]);

        config([
            'marathon.schedule_id' => $schedule->id,
            'beginner_pilot.staffed_schedule_id' => $schedule->id,
            'beginner_pilot.staffing_confirmed_at' => now()->toIso8601String(),
            'beginner_pilot.staffed_schedule_start' => $schedule->start->toIso8601String(),
            'beginner_pilot.staffed_schedule_end' => $schedule->end->toIso8601String(),
        ]);

        return $schedule;
    }
}
