<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пульс, которого нет в расписании, — не пульс (H1713).
 *
 * Проверка вынесена в отдельный класс сознательно: чтобы добраться до
 * расписания, надо разрешить `Schedule`, а Kernel::schedule() по пути читает
 * `MarketingSetting` из базы — то есть нужен RefreshDatabase, который остальным
 * девяти тестам пульса не нужен и стоил бы им прогона миграций на пустом месте.
 *
 * Отказ, который здесь ловится, тихий и потому опасный: команда останется на
 * месте, юнит-тесты останутся зелёными, а сторож просто перестанет выходить на
 * связь. Ровно тот класс молчаливой поломки, ради которого пульс и заводился.
 */
class SchedulerHeartbeatRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_heartbeat_is_registered_in_the_schedule(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'heartbeat:ping'));

        $this->assertCount(1, $events, 'heartbeat:ping не зарегистрирован в расписании');
    }

    public function test_the_heartbeat_frequency_comes_from_config(): void
    {
        config()->set('heartbeat.cron', '*/7 * * * *');

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'heartbeat:ping'));

        $this->assertSame(
            '*/7 * * * *',
            $events->first()?->expression,
            'частота пульса не читается из config/heartbeat.cron'
        );
    }

    /**
     * Выкладка не должна выглядеть как авария: на время maintenance-режима
     * пульс обязан продолжать идти, иначе каждый деплой = ложная тревога.
     */
    public function test_the_heartbeat_keeps_beating_in_maintenance_mode(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'heartbeat:ping'));

        $this->assertNotNull($event);
        $this->assertTrue(
            $event->evenInMaintenanceMode,
            'пульс отключается в maintenance-режиме — каждый деплой станет ложной тревогой'
        );
    }
}
