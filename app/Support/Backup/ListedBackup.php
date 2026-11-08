<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\Tasks\Backup\BackupJob;

/**
 * Backup-запись, собранная из УЖЕ СНЯТОГО листинга каталога: размер и mtime
 * приходят из метаданных одного shallow-листинга (для WebDAV это один
 * Depth-1 PROPFIND на каталог), а не из отдельного запроса на файл.
 *
 * Базовый spatie Backup при конструкции дергает disk->exists(), а date()/
 * sizeInBytes() — disk->lastModified()/disk->size() НА КАЖДЫЙ файл; на 414
 * частей это сотни PROPFIND и >3 минут на backup:list (замер прод .92,
 * 17-10-2026). Здесь все три величины известны из листинга — сетевых
 * запросов на файл нет вовсе.
 *
 * Семантика даты зеркалит spatie Backup::date(): сначала имя файла
 * (формат Y-m-d-H-i-s.zip — зипы local), при неудаче — mtime из листинга
 * (части `*.part-NN-of-NN.zip`). Так строка local и off-site совпадают с
 * дореформенным прогоном по частям/размеру/возрасту.
 */
final class ListedBackup extends Backup
{
    public function __construct(
        Filesystem $disk,
        string $path,
        private readonly int $listingMtime,
        private readonly int $listingBytes,
    ) {
        $this->disk = $disk;
        $this->path = $path;
        $this->exists = true;
    }

    public function date(): Carbon
    {
        try {
            return Carbon::createFromFormat(BackupJob::FILENAME_FORMAT, basename($this->path));
        } catch (InvalidArgumentException) {
            return Carbon::createFromTimestamp($this->listingMtime);
        }
    }

    public function sizeInBytes(): float
    {
        return (float) $this->listingBytes;
    }
}
