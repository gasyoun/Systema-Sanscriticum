<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Support\Facades\Log;
use Spatie\Backup\Exceptions\BackupFailed;
use Spatie\Backup\Tasks\Backup\Zip;
use ZipArchive;

/**
 * Spatie 10.3.1 {@see Zip} that freezes each member to a sibling snapshot
 * before addFile, so a live storage/app shrink after add cannot fail close().
 *
 * FINDINGS §513: PHP 8.3 LENGTH_TO_END raises ER_DATA_LENGTH on shrink;
 * LENGTH_UNCHECKED is not portable (CI libzip: close() Invalid argument).
 * Copying next to the zip (backup-temp, disk) avoids /tmp tmpfs RAM.
 *
 * Нечитаемые файлы (root-owned артефакты фоновых задач: прод 22-08-2026 —
 * 2519 штук) НЕ роняют бэкап: freezeFile не смог — член пропускается, а
 * close() после успеха пишет warning со списком. Раньше молчаливый фолбэк на
 * оригинал уводил close() в «Can't open file: Permission denied» уже ПОСЛЕ
 * минут копирования.
 */
class LiveTreeZip extends Zip
{
    /** @var list<string> */
    private array $snapshots = [];

    /** @var list<string> */
    private array $skipped = [];

    public function add(string|iterable $files, ?string $nameInZip = null): self
    {
        if (is_array($files)) {
            $nameInZip = null;
        }

        if (is_string($files)) {
            $files = [$files];
        }

        $compressionMethod = $this->config->backup->destination->compressionMethod;
        $compressionLevel = $this->config->backup->destination->compressionLevel;
        // ZipArchive::CM_DEFAULT is -1. setCompressionName(-1) is ZIP_ER_INVAL
        // on some libzip builds (CI PHP 8.3.33: close() Invalid argument).
        if ($compressionMethod < 0) {
            $compressionMethod = ZipArchive::CM_DEFLATE;
        }

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->zipFile->addEmptyDir(ltrim($nameInZip ?: $file, DIRECTORY_SEPARATOR));
            }

            if (is_file($file)) {
                $source = $this->freezeFile($file);

                // Нечитаемый источник: снапшот не вышел, оригинал libzip не
                // откроет — пропускаем громко вместо смерти на close().
                if ($source === null) {
                    continue;
                }

                $fileNameInZip = ltrim($nameInZip ?: $file, DIRECTORY_SEPARATOR);

                $this->zipFile->addFile($source, $fileNameInZip);

                $this->zipFile->setCompressionName($fileNameInZip, $compressionMethod, $compressionLevel);

                if ($this->encryptionAlgorithm !== null) {
                    $result = $this->zipFile->setEncryptionName($fileNameInZip, $this->encryptionAlgorithm);

                    if ($result !== true) {
                        throw BackupFailed::from(new \Exception("Failed to set encryption for '{$fileNameInZip}' in zip file at '{$this->pathToZip}'."));
                    }
                }
            }

            $this->fileCount++;
        }

        return $this;
    }

    public function close(): void
    {
        try {
            parent::close();

            if ($this->skipped !== []) {
                Log::warning('backup: недоступные файлы пропущены (не читаются процессом бэкапа)', [
                    'count' => count($this->skipped),
                    'files' => array_slice($this->skipped, 0, 20),
                ]);
            }
        } finally {
            foreach ($this->snapshots as $path) {
                @unlink($path);
            }
            $this->snapshots = [];
            $dir = $this->snapshotDir();
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    private function freezeFile(string $file): ?string
    {
        $dir = $this->snapshotDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $snap = $dir.DIRECTORY_SEPARATOR.hash('sha256', $file);
        if (! @copy($file, $snap)) {
            $this->skipped[] = $file;

            return null;
        }

        $this->snapshots[] = $snap;

        return $snap;
    }

    private function snapshotDir(): string
    {
        return dirname($this->pathToZip).DIRECTORY_SEPARATOR.'live-tree-snap';
    }
}
