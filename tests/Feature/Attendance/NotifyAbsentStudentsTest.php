<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Jobs\SendMessengerAlerts;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NotifyAbsentStudentsTest extends TestCase
{
    use RefreshDatabase;

    private function enableSettings(): void
    {
        MarketingSetting::create([
            'absent_notify_enabled' => true,
            'absent_notify_delay_minutes' => 30,
        ]);
        MarketingSetting::flushCached();
    }

    /** @test */
    public function notifies_only_absent_students_and_dedupes(): void
    {
        Bus::fake();
        $this->enableSettings();

        $group = Group::create(['name' => 'Группа']);
        $present = User::factory()->create(['telegram_id' => 111, 'email' => 'p@example.test']);
        $absent = User::factory()->create(['telegram_id' => 222, 'email' => 'a@example.test']);
        $group->users()->attach([$present->id, $absent->id]);

        // Занятие закончилось ~1.5ч назад (start −3.5ч, end −1.5ч) → позже delay.
        $schedule = Schedule::create([
            'title' => 'Занятие', 'group_id' => $group->id,
            'start' => now()->subHours(3)->subMinutes(30), 'end' => now()->subHours(1)->subMinutes(30),
        ]);

        WebinarAttendance::create([
            'schedule_id' => $schedule->id, 'user_id' => $present->id,
            'zoom_participant_uuid' => 'p1', 'joined_at' => now()->subHours(3),
        ]);

        $this->artisan('classes:notify-absent')->assertSuccessful();

        // Уведомлён только отсутствующий.
        Bus::assertDispatchedTimes(SendMessengerAlerts::class, 1);
        Bus::assertDispatched(SendMessengerAlerts::class, fn ($job) => $job->user->id === $absent->id);

        $this->assertNotNull($schedule->fresh()->absent_notified_at);

        // Повторный прогон — дедуп, ничего нового.
        $this->artisan('classes:notify-absent')->assertSuccessful();
        Bus::assertDispatchedTimes(SendMessengerAlerts::class, 1);
    }

    /** @test */
    public function does_nothing_before_delay_elapses(): void
    {
        Bus::fake();
        $this->enableSettings();

        $group = Group::create(['name' => 'Группа']);
        $student = User::factory()->create(['telegram_id' => 333]);
        $group->users()->attach($student->id);

        // Занятие ещё идёт / только началось — delay не прошёл.
        Schedule::create([
            'title' => 'Идёт', 'group_id' => $group->id,
            'start' => now()->subMinutes(10), 'end' => now()->addMinutes(110),
        ]);

        $this->artisan('classes:notify-absent')->assertSuccessful();
        Bus::assertNotDispatched(SendMessengerAlerts::class);
    }

    /** @test */
    public function disabled_by_default(): void
    {
        Bus::fake();
        // Настроек нет → выключено.
        $this->artisan('classes:notify-absent')->assertSuccessful();
        Bus::assertNotDispatched(SendMessengerAlerts::class);
    }
}
