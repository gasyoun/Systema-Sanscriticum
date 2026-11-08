<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Commands\ListCommand;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\HealthCheck;
use Throwable;

/**
 * backup:list за один Depth-1 листинг на каталог off-site (H6916).
 *
 * Spatie ListCommand через BackupDestinationStatusFactory -> BackupDestination
 * меряет usedStorage()/newestBackup() ВЫЗОВАМИ НА КАЖДЫЙ файл — для WebDAV
 * это отдельный PROPFIND на каждую из 414 частей, то есть >3 минут на прогон
 * (прод .92, 17-10-2026): живая проба списка архивов непригодна, cabinet:probe
 * и guards вынуждены жить на stash с долгим кэшем. Здесь строка каждого диска
 * собирается из одного shallow-листинга каталога назначения
 * ({@see FastBackupDestination}), а health-check'и (возраст, объём) и
 * табличный вывод наследуются у spatie без изменений — числа совпадают с
 * дореформенным прогоном, меняется только время.
 *
 * Регистрация: AppServiceProvider перевязывает Spatie ListCommand::class на
 * этот класс (тот же шов, что BackupCommand::class -> BackupRunCommand), так
 * что `php artisan backup:list` остаётся точкой входа, vendor/ не тронут.
 */
final class BackupListCommand extends ListCommand
{
    protected $signature = 'backup:list';

    protected $description = 'Display a list of all backups (один Depth-1 листинг на каталог off-site).';

    public function handle(): int
    {
        $statuses = collect($this->config->monitoredBackups->monitorBackups)
            ->flatMap(fn (array $monitor): Collection => $this->statusesForMonitor($monitor))
            ->sortBy(fn (BackupDestinationStatus $status): string => $status->backupDestination()->backupName()
                .'-'.$status->backupDestination()->diskName())
            ->values();

        $this->displayOverview($statuses)->displayFailures($statuses);

        return self::SUCCESS;
    }

    /**
     * Та же сборка статусов, что BackupDestinationStatusFactory::createForSingleMonitor
     * (фабрика тоже получает monitor-строки МАССИВАМИ — Collection::toArray
     * разворачивает MonitoredBackupConfig в camelCase-массивы), но destination —
     * {@see FastBackupDestination}, а чтение off-site идёт через
     * readonly-близнеца диска, если он заведён в filesystems.
     *
     * @param  array{name: string, disks: list<string>, healthChecks: array<mixed>}  $monitor
     * @return Collection<int, BackupDestinationStatus>
     */
    private function statusesForMonitor(array $monitor): Collection
    {
        return collect($monitor['disks'])
            ->map(function (string $diskName) use ($monitor): BackupDestinationStatus {
                $probeDiskName = $diskName.'_readonly_probe';

                $probeDisk = (string) config("filesystems.disks.$probeDiskName.driver", '') !== ''
                    ? Storage::disk($probeDiskName)
                    : null;

                try {
                    $destination = new FastBackupDestination(
                        Storage::disk($diskName),
                        (string) $monitor['name'],
                        $diskName,
                        $probeDisk,
                    );
                } catch (Throwable $exception) {
                    // Диск не сконфигурён/не резолвится — та же семантика, что
                    // BackupDestination::create: строка остаётся, но Unreachable.
                    $destination = new FastBackupDestination(null, (string) $monitor['name'], $diskName);
                    $destination->connectionError = $exception;
                }

                return new BackupDestinationStatus($destination, $this->healthChecksFor($monitor));
            });
    }

    /**
     * Зеркало BackupDestinationStatusFactory::buildHealthChecks.
     *
     * @param  array{name: string, disks: list<string>, healthChecks: array<mixed>}  $monitor
     * @return array<int, HealthCheck>
     */
    private function healthChecksFor(array $monitor): array
    {
        return collect($monitor['healthChecks'])
            ->map(function (mixed $options, mixed $class): HealthCheck {
                if (is_int($class)) {
                    $class = $options;
                    $options = [];
                }

                return is_array($options)
                    ? app()->makeWith((string) $class, $options)
                    : new $class($options);
            })
            ->values()
            ->all();
    }
}
