<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\BackupDestination\BackupDestination;

/**
 * BackupDestination, у которого ВСЕ три величины (# of backups, newest,
 * used storage) считаются из ОДНОГО shallow-листинга каталога назначений,
 * а не из spatie-обхода с exists()/lastModified()/size() на каждый файл.
 *
 * Замеры: spatie-обход 476 файлов на Яндекс.Диске = 144 с (0bn) и >3 минут
 * на backup:list (прод .92, 17-10-2026, 414 частей), один shallow-листинг
 * того же каталога = 2.8 с с теми же path/size/lastModified. Для WebDAV
 * shallow-листинг адаптера — один Depth-1 PROPFIND: размеры и mtime уже
 * лежат в multistatus-ответе, отдельных запросов на файл не нужно.
 *
 * Каталог читается через readDisk — readonly-близнец боевого диска
 * (`*_readonly_probe`, тот же шов, что ShellSystemInspector::readBackupDestinations):
 * метаданные те же, а 45-секундный потолок curl рвёт застрявшее чтение и
 * превращает его в штатное Unreachable вместо многочасового зависания.
 *
 * Фильтр зипов — только по расширению: spatie File::isZipFile при не-zip
 * расширении запросил бы mimeType() НА КАЖДЫЙ файл (та же патология, что
 * умирает). Все артефакты обоих дисков кончаются на .zip — зипы local
 * (`Y-m-d-H-i-s.zip`) и части `*.part-NN-of-NN.zip` — так что count/size/
 * newest совпадают с дореформенным прогоном (офлайн-тесты фиксируют).
 */
final class FastBackupDestination extends BackupDestination
{
    /** Сколько листингов снято за прогон — evidence для счётчика листингов/PROPFIND. */
    public static int $listingCount = 0;

    private ?BackupCollection $listingCache = null;

    /** Листинг уже падал — строка диска остаётся со значениями «/», не кидает. */
    private ?Exception $listingError = null;

    public function __construct(
        ?Filesystem $disk,
        string $backupName,
        string $diskName,
        private readonly ?Filesystem $readDisk = null,
    ) {
        parent::__construct($disk, $backupName, $diskName);
    }

    public static function resetListingCount(): void
    {
        self::$listingCount = 0;
    }

    public function isReachable(): bool
    {
        if ($this->disk === null) {
            return false;
        }

        try {
            $this->listOnce();

            return true;
        } catch (Exception $exception) {
            $this->listingError = $exception;
            $this->connectionError = $exception;

            return false;
        }
    }

    public function backups(): BackupCollection
    {
        // spatie convertToRow зовёт backups() и на Unreachable-строке — базовый
        // BackupDestination там возвращает пустую коллекцию (allFiles с
        // try/catch), не кидает. Повторяем семантику: упавший листинг — пустая
        // коллекция, табличные величины гасятся в «/».
        if ($this->listingError !== null) {
            return new BackupCollection;
        }

        try {
            return $this->listOnce();
        } catch (Exception $exception) {
            $this->listingError = $exception;
            $this->connectionError = $exception;

            return new BackupCollection;
        }
    }

    private function listOnce(): BackupCollection
    {
        if ($this->listingCache !== null) {
            return $this->listingCache;
        }

        $readDisk = $this->readDisk ?? $this->disk;
        if ($readDisk === null) {
            throw new Exception('backup destination disk is not configured');
        }

        // Один shallow-листинг каталога через адаптер — для WebDAV это один
        // Depth-1 PROPFIND с размерами и mtime в ответе (тот же вызов, которым
        // ShellSystemInspector::readBackupDestinations меряет свежесть).
        $entries = [];
        foreach ($readDisk->getAdapter()->listContents($this->backupName, false) as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $entries[] = [
                'path' => (string) $item->path(),
                'mtime' => (int) ($item->lastModified() ?? 0),
                'bytes' => (int) ($item->fileSize() ?? 0),
            ];
        }
        self::$listingCount++;

        $backups = collect($entries)
            ->filter(fn (array $entry): bool => pathinfo($entry['path'], PATHINFO_EXTENSION) === 'zip')
            ->map(fn (array $entry): ListedBackup => new ListedBackup($readDisk, $entry['path'], $entry['mtime'], $entry['bytes']))
            ->sortByDesc(fn (ListedBackup $backup): int => $backup->date()->getTimestamp())
            ->values();

        $this->listingCache = new BackupCollection($backups);

        Log::info(sprintf(
            'backup-list: listing disk=%s dir=%s entries=%d files=%d bytes=%d',
            $this->diskName(),
            $this->backupName,
            count($entries),
            $this->listingCache->count(),
            (int) $this->listingCache->sum(fn (ListedBackup $backup): float => $backup->sizeInBytes()),
        ));

        return $this->listingCache;
    }
}
