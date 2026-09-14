<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionObject;
use Tests\TestCase;

/**
 * H3411 Deliverable 3: telegram-harvest-twice-daily-sync had no onFailure hook,
 * unlike every money-adjacent schedule entry (see MoneyCronScheduleHooksTest).
 * A stuck run (MadelineSyncWatchdog::arm() exits non-zero on SIGALRM) or any
 * other non-zero exit vanished into laravel.log with nobody paged.
 */
class TelegramHarvestScheduleHookTest extends TestCase
{
    use RefreshDatabase;

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

    private function afterCallbackCount(Event $event): int
    {
        $ref = new ReflectionObject($event);
        $prop = $ref->getProperty('afterCallbacks');
        $prop->setAccessible(true);
        $callbacks = $prop->getValue($event);

        return is_array($callbacks) ? count($callbacks) : 0;
    }

    public function test_telegram_harvest_sync_has_on_failure_hook(): void
    {
        $event = $this->eventFor('telegram-harvest:sync');

        $this->assertNotNull($event, 'telegram-harvest:sync not found in Kernel schedule');
        $this->assertGreaterThan(
            0,
            $this->afterCallbackCount($event),
            'telegram-harvest:sync must register onFailure (afterCallbacks)'
        );
    }

    public function test_on_failure_finish_logs_critical(): void
    {
        Log::spy();

        $event = $this->eventFor('telegram-harvest:sync');
        $this->assertNotNull($event);

        // Simulate a non-zero exit: Laravel's onFailure wraps then() and checks exitCode.
        $event->finish($this->app, 1);

        Log::shouldHaveReceived('critical')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'schedule.telegram_harvest_sync_failed'
                    && ($context['command'] ?? null) === 'telegram-harvest:sync --json';
            })
            ->atLeast()
            ->once();
    }
}
