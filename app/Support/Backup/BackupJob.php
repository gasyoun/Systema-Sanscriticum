<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Spatie\Backup\Events\BackupZipWasCreated;
use Spatie\Backup\Tasks\Backup\BackupJob as SpatieBackupJob;
use Spatie\Backup\Tasks\Backup\Manifest;

/**
 * Same as Spatie 10.3.1 BackupJob except the zip is {@see LiveTreeZip}.
 */
class BackupJob extends SpatieBackupJob
{
    protected function createZipContainingEveryFileInManifest(Manifest $manifest): string
    {
        backupLogger()->info("Zipping {$manifest->count()} files and directories...");

        $pathToZip = $this->temporaryDirectory->path($this->config->backup->destination->filenamePrefix.$this->filename);

        $zip = LiveTreeZip::createForManifest($manifest, $pathToZip);

        backupLogger()->info("Created zip containing {$zip->count()} files and directories. Size is {$zip->humanReadableSize()}");

        event(new BackupZipWasCreated($pathToZip));

        if ($this->config->backup->verifyBackup) {
            $this->verifyBackup($pathToZip, $manifest->count());
        }

        return $pathToZip;
    }
}
