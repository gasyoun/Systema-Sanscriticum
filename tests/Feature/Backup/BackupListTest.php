<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupListCommand;
use App\Support\Backup\FastBackupDestination;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * H6916: строка off-site в backup:list обязана считаться из ОДНОГО
 * Depth-1 листинга каталога (не запроса на файл) и совпасть по числу
 * частей, размеру, возрасту новейшего и Healthy с дореформенным прогоном.
 * Офлайн: faked disk, сети нет.
 *
 * Прогон идёт напрямую через command->run с BufferedOutput: тестовый
 * PendingCommand РВЁТСЯ на табличном выводе (Symfony Table пишет строку
 * таблицы одним doWrite-массивом, а expectsOutputToContain зовёт
 * str_contains на каждый such массив).
 */
class BackupListTest extends TestCase
{
    private const BACKUP_NAME = 'Laravel';

    protected function setUp(): void
    {
        parent::setUp();

        FastBackupDestination::resetListingCount();

        config()->set('backup.backup.name', self::BACKUP_NAME);
        config()->set('backup.monitor_backups', [
            [
                'name' => self::BACKUP_NAME,
                'disks' => ['offsite'],
                'health_checks' => [
                    MaximumAgeInDays::class => 8,
                    MaximumStorageInMegabytes::class => 15000,
                ],
            ],
        ]);

        $disk = Storage::fake('offsite');
        $this->putParts($disk);

        // Якорные mtime: у частей (*.part-NN-of-NN.zip) spatie берёт дату из
        // mtime, не из имени, — детерминируем возраст тем же способом.
        $now = time();
        $this->touchParts($disk, $now - 86400, $now - 9 * 86400);
    }

    public function test_offsite_row_from_single_listing_matches_spatie_values(): void
    {
        Log::spy();

        [$exit, $output] = $this->runBackupList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('| Laravel | offsite | ✅', $output);
        $this->assertMatchesRegularExpression('/\|\s+4\s+\|/', $output);
        $this->assertStringContainsString('5.86 KB', $output);
        $this->assertStringContainsString('1.00 (', $output);
        $this->assertStringNotContainsString('notes.txt', $output);

        // Один каталог — один листинг за весь прогон (isReachable + backups +
        // оба health-check'а читают один кэш).
        $this->assertSame(1, FastBackupDestination::$listingCount);

        // Evidence-строка счётчика: 5 записей каталога, 4 зипа, 6000 байт.
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains(
            $message,
            'backup-list: listing disk=offsite dir='.self::BACKUP_NAME.' entries=5 files=4 bytes=6000',
        ));
    }

    public function test_stale_newest_group_is_unhealthy(): void
    {
        $disk = Storage::disk('offsite');
        $now = time();
        $this->touchParts($disk, $now - 2 * 86400, $now - 9 * 86400);

        config()->set('backup.monitor_backups.0.health_checks', [
            MaximumAgeInDays::class => 1,
            MaximumStorageInMegabytes::class => 15000,
        ]);

        [$exit, $output] = $this->runBackupList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Unhealthy backup destinations', $output);
        $this->assertStringContainsString('considered too old', $output);
    }

    public function test_local_row_same_numbers(): void
    {
        config()->set('backup.monitor_backups', [
            [
                'name' => self::BACKUP_NAME,
                'disks' => ['local'],
                'health_checks' => [
                    MaximumAgeInDays::class => 8,
                    MaximumStorageInMegabytes::class => 15000,
                ],
            ],
        ]);

        $local = Storage::fake('local');
        $local->put(self::BACKUP_NAME.'/2026-10-12-20-04-36.zip', str_repeat('Z', 4 * 1024 * 1024));

        [$exit, $output] = $this->runBackupList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('| Laravel | local |', $output);
        $this->assertMatchesRegularExpression('/\|\s+1\s+\|/', $output);
        $this->assertStringContainsString('4 MB', $output);
        $this->assertSame(1, FastBackupDestination::$listingCount);
    }

    public function test_unreachable_disk_degrades_inside_the_run(): void
    {
        $real = Storage::fake('badcreds');
        $throwing = new class($real->getDriver(), $real->getAdapter(), ['root' => '/']) extends FilesystemAdapter
        {
            public function getAdapter(): FlysystemAdapter
            {
                return new class(sys_get_temp_dir()) extends LocalFilesystemAdapter
                {
                    public function listContents(string $location, bool $deep): iterable
                    {
                        throw new RuntimeException('401 Unauthorized (negative control)');
                    }
                };
            }
        };
        Storage::set('badcreds', $throwing);

        config()->set('backup.monitor_backups', [
            [
                'name' => self::BACKUP_NAME,
                'disks' => ['badcreds'],
                'health_checks' => [
                    MaximumAgeInDays::class => 8,
                    MaximumStorageInMegabytes::class => 15000,
                ],
            ],
        ]);

        // Негатив-контроль: листинг падает — строка живёт, значения «/»,
        // Unreachable фиксируется, прогон завершается сам (на проде потолок
        // диска 45 с рвёт застрявшее чтение тем же кодовым путём).
        [$exit, $output] = $this->runBackupList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('| Laravel | badcreds | ❌', $output);
        $this->assertStringContainsString('Unhealthy backup destinations', $output);
        $this->assertMatchesRegularExpression('/\|\s+\/\s+\|/', $output);

        // Ни один листинг не досчитался — счётчик не растёт.
        $this->assertSame(0, FastBackupDestination::$listingCount);
    }

    public function test_offsite_is_read_through_readonly_twin(): void
    {
        // Близнец заведён в filesystems, как yandex_disk_readonly_probe на
        // проду: строка обязана называться offsite, а числа прийти с близнеца.
        config()->set('filesystems.disks.offsite_readonly_probe', [
            'driver' => 'webdav_probe',
            'baseUri' => 'https://example.invalid',
            'username' => null,
            'password' => null,
            'prefix' => '',
        ]);
        $twin = Storage::fake('offsite_readonly_probe');
        $this->putParts($twin);
        $now = time();
        $this->touchParts($twin, $now - 86400, $now - 9 * 86400);

        Log::spy();

        [$exit, $output] = $this->runBackupList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('| Laravel | offsite | ✅', $output);
        $this->assertStringContainsString('5.86 KB', $output);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains(
            $message,
            'backup-list: listing disk=offsite dir='.self::BACKUP_NAME.' entries=5 files=4 bytes=6000',
        ));
        $this->assertSame(1, FastBackupDestination::$listingCount);
    }

    /**
     * Полный прогон backup:list с захватом вывода (без PendingCommand —
     * он рвётся на табличных doWrite-массивах, см. class docblock).
     *
     * @return array{0: int, 1: string}
     */
    private function runBackupList(): array
    {
        $buffered = new BufferedOutput;
        $buffered->setDecorated(false);

        /** @var BackupListCommand $command */
        $command = $this->app->make(BackupListCommand::class);
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffered));

        $exit = $command->run(new ArrayInput([]), $buffered);

        return [$exit, $buffered->fetch()];
    }

    private function putParts(FilesystemAdapter $disk): void
    {
        $disk->put(self::BACKUP_NAME.'/2026-10-20-07-11-16.part-01-of-02.zip', str_repeat('A', 1000));
        $disk->put(self::BACKUP_NAME.'/2026-10-20-07-11-16.part-02-of-02.zip', str_repeat('B', 2000));
        $disk->put(self::BACKUP_NAME.'/2026-10-13-07-11-16.part-01-of-02.zip', str_repeat('C', 1000));
        $disk->put(self::BACKUP_NAME.'/2026-10-13-07-11-16.part-02-of-02.zip', str_repeat('D', 2000));
        // Не-zip мусор в числе бэкапов не считается — как и у spatie.
        $disk->put(self::BACKUP_NAME.'/notes.txt', 'x');
    }

    private function touchParts(FilesystemAdapter $disk, int $newestMtime, int $oldestMtime): void
    {
        touch($disk->path(self::BACKUP_NAME.'/2026-10-20-07-11-16.part-01-of-02.zip'), $newestMtime);
        touch($disk->path(self::BACKUP_NAME.'/2026-10-20-07-11-16.part-02-of-02.zip'), $newestMtime);
        touch($disk->path(self::BACKUP_NAME.'/2026-10-13-07-11-16.part-01-of-02.zip'), $oldestMtime);
        touch($disk->path(self::BACKUP_NAME.'/2026-10-13-07-11-16.part-02-of-02.zip'), $oldestMtime);
    }
}
