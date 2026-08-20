<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Spatie\Backup\Exceptions\BackupFailed;
use Spatie\Backup\Tasks\Backup\Zip;
use ZipArchive;

/**
 * Spatie 10.3.1 {@see Zip} plus PHP 8.3 {@see ZipArchive::LENGTH_UNCHECKED}.
 *
 * FINDINGS §513: default LENGTH_TO_END freezes each member's size at addFile
 * and raises ER_DATA_LENGTH ("Unexpected length of data") at close() if the
 * live tree shrank. This class reads whatever bytes remain.
 */
class LengthUncheckedZip extends Zip
{
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
        $length = defined('ZipArchive::LENGTH_UNCHECKED')
            ? ZipArchive::LENGTH_UNCHECKED
            : ZipArchive::LENGTH_TO_END;

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->zipFile->addEmptyDir(ltrim($nameInZip ?: $file, DIRECTORY_SEPARATOR));
            }

            if (is_file($file)) {
                $fileNameInZip = ltrim($nameInZip ?: $file, DIRECTORY_SEPARATOR);

                $this->zipFile->addFile($file, $fileNameInZip, 0, $length);

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
}
