<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ServerGuards\ShellSystemInspector;
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
    public function local_is_the_only_direct_destination_and_yandex_goes_through_split_upload(): void
    {
        // Yandex WebDAV режет PUT >1 ГБ по HTTP 413, поэтому spatie больше не
        // пишет туда напрямую: off-site нога — SplitUploadToYandex (части).
        $disks = config('backup.backup.destination.disks');

        $this->assertSame(['local'], $disks);
        $this->assertSame('yandex_disk', config('backup.backup.split_upload.disk'));

        // Аудит свежести guards обязан видеть оба диска, несмотря на то что
        // в destination.disks остался только local.
        $inspector = new ShellSystemInspector;
        $rows = $inspector->backupDestinations();
        $this->assertNotNull($rows);
        $audited = array_column($rows, 'disk');
        $this->assertContains('local', $audited);
        $this->assertContains('yandex_disk', $audited);

        // Контракт размера: 20 МиБ — класс, переживающий Яндекс 22–24-08-2026
        // (решение MG 24-08-2026: срез с 50 после TLS-stall на 50 МиБ частях;
        // ≤~20 МиБ доезжали честно все дни наблюдений). Порог
        // BACKUP_MIN_ARCHIVE_MB в scripts/server_guards.conf стоит НИЖЕ суммы
        // полной группы — связка меняется только парой.
        $this->assertSame(20, (int) config('backup.backup.split_upload.max_part_mb'));
    }

    /** @test */
    public function yandex_disk_disk_uses_the_webdav_driver(): void
    {
        $this->assertSame('webdav', config('filesystems.disks.yandex_disk.driver'));
    }

    /** @test */
    public function file_storage_is_included_in_the_backup(): void
    {
        $include = config('backup.backup.source.files.include');

        $this->assertContains(storage_path('app'), $include);
    }

    /** @test */
    public function backup_run_command_is_not_restricted_to_db_only(): void
    {
        $event = $this->eventFor('backup:run');

        $this->assertNotNull($event);
        $this->assertStringNotContainsString('--only-db', (string) $event->command);
    }
}
