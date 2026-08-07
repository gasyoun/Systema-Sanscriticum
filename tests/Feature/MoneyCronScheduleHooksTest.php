<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ScheduleFailureSignal;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionObject;
use Tests\TestCase;

/**
 * H2338 / audit specs 6–7: daily integrity auditor + onFailure hooks on core
 * money crons; stale-checkout reaper registered even when its feature flag is OFF.
 *
 * Laravel 12 stores onFailure via then() → afterCallbacks (exitCode checked at run).
 */
class MoneyCronScheduleHooksTest extends TestCase
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

    public function test_checkout_integrity_auditor_is_registered_daily(): void
    {
        $event = $this->eventFor('payments:audit-checkout-integrity');

        $this->assertNotNull($event, 'payments:audit-checkout-integrity not in Kernel schedule');
        // dailyAt('04:05') → «5 4 * * *»
        $this->assertSame('5 4 * * *', $event->expression);
        $this->assertGreaterThan(
            0,
            $this->afterCallbackCount($event),
            'auditor schedule entry must have onFailure (afterCallbacks via then())'
        );
    }

    /**
     * @dataProvider moneyCronNeedles
     */
    public function test_money_cron_has_on_failure_hook(string $needle): void
    {
        $event = $this->eventFor($needle);

        $this->assertNotNull($event, "{$needle} not found in Kernel schedule");
        $this->assertGreaterThan(
            0,
            $this->afterCallbackCount($event),
            "{$needle} must register onFailure (afterCallbacks)"
        );
    }

    public static function moneyCronNeedles(): array
    {
        return [
            'promises:expire' => ['promises:expire'],
            'receivables:check' => ['receivables:check'],
            'debts:remind' => ['debts:remind'],
            'expire-stale-checkouts' => ['payments:expire-stale-checkouts'],
        ];
    }

    public function test_stale_checkout_reaper_is_registered_even_when_feature_flag_is_off(): void
    {
        // Must set before Schedule is resolved (Kernel::schedule reads config once).
        config()->set('features.checkout_stale_order_expiry', false);
        $this->app->forgetInstance(Schedule::class);

        $event = $this->eventFor('payments:expire-stale-checkouts');

        $this->assertNotNull(
            $event,
            'reaper must stay registered when CHECKOUT_STALE_ORDER_EXPIRY is false (command self-gates)'
        );
        $this->assertGreaterThan(0, $this->afterCallbackCount($event));
    }

    public function test_on_failure_finish_fires_schedule_failure_signal(): void
    {
        Log::spy();

        $event = $this->eventFor('promises:expire');
        $this->assertNotNull($event);

        // Simulate a non-zero exit: Laravel's onFailure wraps then() and checks exitCode.
        $event->finish($this->app, 1);

        Log::shouldHaveReceived('critical')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'schedule.money_command_failed'
                    && ($context['command'] ?? null) === 'promises:expire';
            })
            ->atLeast()
            ->once();
    }

    public function test_schedule_failure_signal_logs_critical(): void
    {
        Log::spy();

        ScheduleFailureSignal::report('promises:expire');

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'schedule.money_command_failed'
                    && ($context['command'] ?? null) === 'promises:expire';
            });
    }
}
