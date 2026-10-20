<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\Helpers\File;
use Spatie\Backup\Tasks\Backup\BackupJob;
use Throwable;

/**
 * Бэкап-архив, посчитанный ИЗ готового листинга каталога (одна Depth-1
 * PROPFIND на каталог), а не отдельными запросами на файл.
 *
 * Родительский {@see Backup} на каждый файл делает до трёх сетевых вызовов:
 * конструктор — exists(), sizeInBytes() — size(), date() — lastModified()
 * (имена частей `<ts>.part-NN-of-NN.zip` не парсятся как `Y-m-d-H-i-s.zip`,
 * поэтому falling back на mtime). На off-site группе из 414 частей это
 * ~1240 PROPFIND'ов на один backup:list. Здесь все три величины берутся из
 * уже полученного листинга — сетевых вызовов на файл ноль.
 *
 * Приватные кэши родителя ($cachedDate/$cachedSize) не трогаем — переопределяем
 * методы целиком; конструктор родителя НЕ вызывается, он делает exists()-пробу
 * по сети.
 */
final class ListedBackup extends Backup
{
    private int $listingSizeBytes;

    private int $listingLastModified;

    private ?Carbon $dateCache = null;

    public function __construct(Filesystem $disk, string $path, int $sizeBytes, int $lastModified)
    {
        $this->disk = $disk;
        $this->path = $path;
        $this->exists = true;
        $this->listingSizeBytes = $sizeBytes;
        $this->listingLastModified = $lastModified;
    }

    public function sizeInBytes(): float
    {
        return (float) $this->listingSizeBytes;
    }

    public function date(): Carbon
    {
        if ($this->dateCache === null) {
            // Точно та же семантика, что у родителя: имя вида Y-m-d-H-i-s.zip
            // парсится из имени файла, всё остальное (части) — из mtime листинга.
            try {
                $this->dateCache = Carbon::createFromFormat(BackupJob::FILENAME_FORMAT, basename($this->path));
            } catch (InvalidArgumentException) {
                $this->dateCache = Carbon::createFromTimestamp($this->listingLastModified);
            }
        }

        return $this->dateCache;
    }

    /**
     * Фильтр «это бэкап-файл» без сети: расширение .zip решает сразу (все
     * части SplitUploadToYandex и полные архивы кончаются на .zip); для прочих
     * расширений берётся mime из листинга, если он там есть — тем самым
     * семантика повторяет Spatie\Backup\Helpers\File::isZipFile, только без
     * mimeType()-запроса на файл.
     *
     * @param  array<int, array{path: string, size: int, lastModified: int, mimeType: ?string}>  $entries
     */
    public static function collectionFromListing(Filesystem $disk, array $entries): BackupCollection
    {
        $file = new File;

        $backups = collect($entries)
            ->filter(function (array $entry) use ($file): bool {
                if ($file->isZipFile(null, $entry['path'])) {
                    return true;
                }

                return $entry['mimeType'] !== null
                    && in_array($entry['mimeType'], ['application/zip', 'application/x-zip', 'application/x-gzip'], true);
            })
            ->map(fn (array $entry): ListedBackup => new ListedBackup(
                $disk,
                $entry['path'],
                $entry['size'],
                $entry['lastModified'],
            ))
            ->sortByDesc(fn (ListedBackup $backup) => $backup->date()->timestamp)
            ->values();

        return new BackupCollection($backups->all());
    }
}
