<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Exception;
use League\Flysystem\FileAttributes;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\BackupDestination\BackupCollection;
use Throwable;

use function class_basename;

/**
 * BackupDestination, который считает всю строку backup:list из ОДНОГО
 * Depth-1 листинга каталога бэкапа.
 *
 * Родитель на off-site диске (WebDAV/sabre) делает: isReachable() → files()
 * (PROPFIND), backups() → allFiles() (PROPFIND), а дальше — на КАЖДЫЙ файл —
 * exists()/size()/lastModified() (по PROPFIND на каждую). Off-site группа из
 * 414 частей ≈ 1240 round-trip'ов на один `php artisan backup:list`, команда
 * уходит за 3 минуты (H6916). Здесь ровно один listContents(): из его
 * метаданных собираются и количество, и размеры, и возраст новейшего; health
 * checks (MaximumAgeInDays, MaximumStorageInMegabytes) работают поверх того же
 * кэша, поэтому пороги и вердикты Healthy не меняются.
 *
 * Ошибки листинга ведут себя как у родителя: backups() глотает исключение
 * (пустая коллекция), isReachable() возвращает false и запоминает
 * connectionError — строка становится Unreachable, а не Healthy.
 */
final class FastWebdavListDestination extends BackupDestination
{
    /** @var array<int, array{path: string, size: int, lastModified: int, mimeType: ?string}>|null */
    private ?array $listingCache = null;

    /**
     * Fast-путь имеет смысл только для WebDAV-дисков: sabre-адаптер ходит по
     * сети на каждый файл. Локальные диски остаются на родительском коде —
     * строка local обязана не измениться вообще.
     */
    public static function shouldHandle(BackupDestination $destination): bool
    {
        $disk = $destination->disk();

        if ($disk === null) {
            return false;
        }

        return str_contains(strtolower(class_basename($disk->getAdapter()::class)), 'webdav');
    }

    /**
     * @return array<int, array{path: string, size: int, lastModified: int, mimeType: ?string}>
     */
    private function listing(): array
    {
        if ($this->listingCache !== null) {
            return $this->listingCache;
        }

        $entries = [];

        foreach ($this->disk->listContents($this->backupName) as $attributes) {
            if (! $attributes->isFile()) {
                continue;
            }

            $entries[] = [
                'path' => $attributes->path(),
                'size' => (int) ($attributes->fileSize() ?? 0),
                'lastModified' => (int) ($attributes->lastModified() ?? 0),
                'mimeType' => $attributes instanceof FileAttributes ? $attributes->mimeType() : null,
            ];
        }

        $this->listingCache = $entries;

        // Счётчик PROPFIND для H6916: ровно одна Depth-1 PROPFIND на каталог
        // вместо одной на каждый файл. Лог — диагностика, падать из-за неё
        // нельзя.
        try {
            backupLogger()->info(sprintf(
                'propfind: dir=%s entries=%d depth=1 (single-listing fast path)',
                $this->backupName,
                count($entries),
            ));
        } catch (Throwable) {
        }

        return $this->listingCache;
    }

    public function isReachable(): bool
    {
        if ($this->disk === null) {
            return false;
        }

        try {
            $this->listing();

            return true;
        } catch (Exception $exception) {
            $this->connectionError = $exception;

            return false;
        }
    }

    public function backups(): BackupCollection
    {
        if ($this->backupCollectionCache) {
            return $this->backupCollectionCache;
        }

        // Родитель так же глотает ошибку листинга и отдаёт пустую коллекцию.
        try {
            $entries = $this->listing();
        } catch (Throwable) {
            $entries = [];
        }

        return $this->backupCollectionCache = ListedBackup::collectionFromListing($this->disk, $entries);
    }
}
