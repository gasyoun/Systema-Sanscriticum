<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Backup\BackupJob as SpatieBackupJob;
use Spatie\Backup\Tasks\Backup\BackupJobFactory as SpatieBackupJobFactory;

class BackupJobFactory extends SpatieBackupJobFactory
{
    public static function createFromConfig(Config $config): SpatieBackupJob
    {
        return (new BackupJob($config))
            ->setFileSelection(static::createFileSelection($config->backup->source->files))
            ->setDbDumpers(static::createDbDumpers($config->backup->source->databases))
            ->setBackupDestinations(BackupDestinationFactory::createFromArray($config));
    }
}
