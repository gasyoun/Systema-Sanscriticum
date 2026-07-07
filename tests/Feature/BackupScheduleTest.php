<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * До этой задачи spatie/laravel-backup был установлен и настроен, но
 * backup:run никогда не вызывался — ноль автоматических бэкапов на практике.
 * Проверяем, что еженедельный запуск действительно висит в Kernel::schedule().
 */
class BackupScheduleTest extends TestCase
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

    /** @test */
    public function backup_run_is_registered_weekly(): void
    {
        $event = $this->eventFor('backup:run');

        $this->assertNotNull($event, 'backup:run не найден в расписании Kernel');
        // weeklyOn(1, '02:00') → «0 2 * * 1».
        $this->assertSame('0 2 * * 1', $event->expression);
    }

    /** @test */
    public function backup_clean_is_registered_weekly(): void
    {
        $event = $this->eventFor('backup:clean');

        $this->assertNotNull($event, 'backup:clean не найден в расписании Kernel');
        // weeklyOn(1, '02:30') → «30 2 * * 1».
        $this->assertSame('30 2 * * 1', $event->expression);
    }

    /** @test */
    public function backup_destinations_include_local_and_yandex_disk(): void
    {
        $disks = config('backup.backup.destination.disks');

        $this->assertContains('local', $disks);
        $this->assertContains('yandex_disk', $disks);
    }

    /** @test */
    public function yandex_disk_disk_uses_the_webdav_driver(): void
    {
        $this->assertSame('webdav', config('filesystems.disks.yandex_disk.driver'));
    }
}
