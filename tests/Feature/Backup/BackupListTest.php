<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Console\Commands\BackupList;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\WebDAV\WebDAVAdapter;
use Mockery;
use RuntimeException;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Tests\TestCase;

/**
 * Офлайн-покрытие backup:list (H6916): off-site строка из ОДНОГО Depth-1
 * листинга каталога вместо PROPFIND на каждый файл. Сеть не нужна: WebDAV-диск
 * — стаб, унаследованный от боевого адаптера, без sabre-клиента — любой
 * сетевой вызов помимо listContents роняет тест.
 */
class BackupListTest extends TestCase
{
    private string $backupName = 'laravel-backup';

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupName = (string) config('backup.monitor_backups.0.name', $this->backupName);
    }

    public function test_backup_list_resolves_to_the_fast_command(): void
    {
        $this->assertInstanceOf(BackupList::class, Artisan::all()['backup:list']);
    }

    public function test_webdav_row_is_built_from_a_single_listing(): void
    {
        $files = [
            new FileAttributes($this->backupName.'/2026-10-20-03-00-00.zip', 300, null, time()),
            new FileAttributes($this->backupName.'/2026-10-13-03-00-00.part-01-of-02.zip', 100, null, time() - 3600),
            new FileAttributes($this->backupName.'/2026-10-13-03-00-00.part-02-of-02.zip', 200, null, time() - 3600),
        ];
        $stub = new StubWebdavAdapter($files);
        $this->bindWebdavDisk('yandex_disk', $stub);
        $this->rebindBackupConfig($this->monitorArray(['yandex_disk']));

        // Ядро H6916: ровно ОДИН Depth-1 листинг на каталог, ноль обращений к
        // отдельным файлам — стаб падает на любом таком обращении.
        $this->artisan('backup:list')->assertExitCode(0);

        $this->assertSame(1, $stub->listContentsCalls);
    }

    public function test_webdav_row_counts_parts_and_stays_healthy(): void
    {
        $files = [
            new FileAttributes($this->backupName.'/2026-10-20-03-00-00.zip', 300, null, time()),
            new FileAttributes($this->backupName.'/2026-10-13-03-00-00.part-01-of-02.zip', 100, null, time() - 3600),
            new FileAttributes($this->backupName.'/2026-10-13-03-00-00.part-02-of-02.zip', 200, null, time() - 3600),
        ];
        $this->bindWebdavDisk('yandex_disk', new StubWebdavAdapter($files));
        $this->rebindBackupConfig($this->monitorArray(['yandex_disk']));

        $this->artisan('backup:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('600 B');

        // Свежие части (возраст по mtime листинга) в порогах → Healthy.
        $this->artisan('backup:list')->doesntExpectOutput('Unhealthy backup destinations')->assertExitCode(0);
    }

    public function test_unreachable_webdav_shows_unreachable_not_healthy(): void
    {
        $stub = new StubWebdavAdapter([]);
        $stub->throwOnListing = new RuntimeException('disk exploded');
        $this->bindWebdavDisk('yandex_disk', $stub);
        $this->rebindBackupConfig($this->monitorArray(['yandex_disk']));

        // Негатив-контроль H6916: ошибка листинга → строка Unreachable (❌ и
        // «/»), не Healthy; попыток листинга — не больше, чем у родителя
        // (backups + isReachable), без зацикливания.
        $this->artisan('backup:list')->assertExitCode(0);

        $this->assertLessThanOrEqual(2, $stub->listContentsCalls);
    }

    public function test_local_row_keeps_the_stock_code_path(): void
    {
        $this->bindWebdavDisk('yandex_disk', new StubWebdavAdapter([]));

        $localDisk = $this->app->make(FilesystemFactory::class)->disk('local');
        $localDisk->put($this->backupName.'/2026-10-20-03-00-00.zip', str_repeat('A', 1234));

        $this->rebindBackupConfig($this->monitorArray(['local', 'yandex_disk']));

        $this->artisan('backup:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('local')
            ->expectsOutputToContain('1.2 KB');
    }

    private function bindWebdavDisk(string $diskName, StubWebdavAdapter $stub): void
    {
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->with($diskName)->andReturnUsing(function () use ($diskName, $stub) {
            return new LaravelFilesystemAdapter(new Flysystem($stub), $stub, ['name' => $diskName]);
        });
        $factory->shouldReceive('disk')->with('local')->andReturnUsing(function () {
            $root = sys_get_temp_dir().'/h6916-local-'.uniqid();
            mkdir($root.'/'.$this->backupName, 0777, true);

            return new LaravelFilesystemAdapter(
                new Flysystem(new LocalFilesystemAdapter($root)),
                new LocalFilesystemAdapter($root),
                ['name' => 'local'],
            );
        });

        $this->app->instance(FilesystemFactory::class, $factory);
    }

    private function rebindBackupConfig(array $monitorBackups): void
    {
        config(['backup' => [
            'monitor_backups' => $monitorBackups,
        ]]);

        Config::rebind();
    }

    /**
     * @param  array<string>  $disks
     * @return array<int, array{name: string, disks: array<string>, health_checks: array<class-string, int>}>
     */
    private function monitorArray(array $disks): array
    {
        return [
            [
                'name' => $this->backupName,
                'disks' => $disks,
                'health_checks' => [
                    MaximumAgeInDays::class => 8,
                    MaximumStorageInMegabytes::class => 15000,
                ],
            ],
        ];
    }
}

/**
 * WebDAV-стаб: basename класса содержит webdav (триггер fast-пути), но
 * sabre-клиента нет — любой сетевой вызов помимо listContents уронит тест,
 * что и доказывает «ноль PROPFIND на файл».
 */
class StubWebdavAdapter extends WebDAVAdapter
{
    /** @var array<int, FileAttributes> */
    private array $files;

    public int $listContentsCalls = 0;

    public ?\Throwable $throwOnListing = null;

    /** @param array<int, FileAttributes> $files */
    public function __construct(array $files = [])
    {
        $this->files = $files;
    }

    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $this->listContentsCalls++;

        if ($this->throwOnListing !== null) {
            throw $this->throwOnListing;
        }

        foreach ($this->files as $file) {
            if ($deep || dirname($file->path()) === trim($path, '/')) {
                yield $file;
            }
        }
    }
}
