<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Backup\FastWebdavListDestination;
use Illuminate\Support\Collection;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Commands\ListCommand;
use Spatie\Backup\Support\Data;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\HealthCheck;

/**
 * backup:list с быстрым off-site путём (H6916).
 *
 * Стоковый spatie ListCommand меряет off-site строку НА КАЖДЫЙ файл:
 * BackupDestination::backups() → allFiles(), затем на каждую часть
 * exists()/size()/lastModified() — по PROPFIND через sabre на каждую из 414
 * частей, итого >1200 round-trip'ов и >3 минут на прогон. Живой прогон списка
 * архивов становится непригодным (cabinet:probe/guards читают stash с долгим
 * кэшем).
 *
 * Эта команда — drop-in замена с той же сигнатурой backup:list и тем же
 * выводом (renderers наследуются у spatie без правок): для WebDAV-дисков
 * off-site строка считается из ОДНОЙ Depth-1 PROPFIND на каталог
 * ({@see FastWebdavListDestination}), локальные диски идут родительским кодом.
 * Количество частей, суммарный размер, возраст новейшего и Healthy считаются
 * по тем же health checks с теми же порогами.
 *
 * НЕ трогает: путь выгрузки SplitUploadToYandex, restic-ногу (H6124), гейт
 * H3371 (S3 bucket+key), пороги health-check'ов и BACKUP_MAX_STORAGE_MB.
 */
class BackupList extends ListCommand
{
    protected $signature = 'backup:list';

    protected $description = 'Display a list of all backups.';

    public function handle(): int
    {
        $statuses = collect($this->config->monitoredBackups->monitorBackups)
            ->flatMap(fn ($monitorProperties) => $this->statusesForMonitor($this->monitorPropertiesAsArray($monitorProperties)))
            ->sortBy(fn (BackupDestinationStatus $status) => $status->backupDestination()->backupName().'-'.
                $status->backupDestination()->diskName())
            ->values();

        $this->displayOverview($statuses)->displayFailures($statuses);

        return static::SUCCESS;
    }

    /**
     * Точная копия сборки spatie (BackupDestinationStatusFactory), с единственным
     * отличием: WebDAV-диск получает FastWebdavListDestination вместо
     * родительского BackupDestination.
     *
     * @param  array{name: string, disks: array<string>, healthChecks: array<class-string|int, array<string, mixed>>}  $monitorConfig
     * @return Collection<int, BackupDestinationStatus>
     */
    private function statusesForMonitor(array $monitorConfig): Collection
    {
        $healthChecks = collect($monitorConfig['healthChecks'])
            ->map(function ($options, $class): HealthCheck {
                if (is_int($class)) {
                    $class = $options;
                    $options = [];
                }

                if (! is_array($options)) {
                    return new $class($options);
                }

                return app()->makeWith($class, $options);
            })
            ->values()
            ->all();

        return collect($monitorConfig['disks'])
            ->map(function ($diskName) use ($monitorConfig, $healthChecks): BackupDestinationStatus {
                $destination = BackupDestination::create($diskName, $monitorConfig['name']);

                if (FastWebdavListDestination::shouldHandle($destination)) {
                    $destination = new FastWebdavListDestination(
                        $destination->disk(),
                        $monitorConfig['name'],
                        $diskName,
                    );
                }

                return new BackupDestinationStatus($destination, $healthChecks);
            });
    }

    /**
     * Конфиг монитора приходит из spatie как Data-объект; выравниваем в массив
     * с camelCase-ключами, которым пользуется и родительская фабрика.
     */
    private function monitorPropertiesAsArray(mixed $monitorProperties): array
    {
        if ($monitorProperties instanceof Data) {
            return $monitorProperties->toArray();
        }

        return (array) $monitorProperties;
    }
}
