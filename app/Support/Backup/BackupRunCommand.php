<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Exception;
use Spatie\Backup\Commands\BackupCommand;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Exceptions\BackupFailed;
use Spatie\Backup\Exceptions\InvalidCommand;
use Spatie\Backup\Notifications\EventHandler;

/**
 * Spatie backup:run with {@see BackupJobFactory} so zip creation uses
 * {@see LiveTreeZip} (FINDINGS §513 / H3195).
 *
 * Keep option handling in lockstep with spatie/laravel-backup 10.3.1
 * BackupCommand::handle — the only delta is the factory class.
 */
class BackupRunCommand extends BackupCommand
{
    public function handle(): int
    {
        backupLogger()->comment($this->currentTry > 1 ? sprintf('Attempt n°%d...', $this->currentTry) : 'Starting backup...');

        if ($this->option('disable-notifications')) {
            EventHandler::disable();
        }

        if ($this->option('timeout') && is_numeric($this->option('timeout'))) {
            set_time_limit((int) $this->option('timeout'));
        }

        if ($this->option('config')) {
            $this->config = Config::fromArray(config($this->option('config')));
        }

        try {
            $this->guardAgainstInvalidOptions();

            $backupJob = BackupJobFactory::createFromConfig($this->config);

            if ($this->option('only-db')) {
                $backupJob->dontBackupFilesystem();
            }

            if ($this->option('db-name')) {
                $backupJob->onlyDbName($this->option('db-name'));
            }

            if ($this->option('only-files')) {
                $backupJob->dontBackupDatabases();
            }

            if ($this->option('only-to-disk')) {
                $backupJob->onlyBackupTo($this->option('only-to-disk'));
            }

            if ($this->option('filename')) {
                $backupJob->setFilename($this->option('filename'));
            }

            if ($this->option('filename-suffix')) {
                $backupJob->appendToFilename($this->option('filename-suffix'));
            }

            if ($excludes = $this->option('exclude')) {
                $backupJob->fileSelection()->excludeFilesFrom($excludes);
            }

            if ($destinationPath = $this->option('destination-path')) {
                $backupJob->setDestinationPath($destinationPath);
            }

            $this->setTries('backup');

            if (! $this->getSubscribedSignals()) {
                $backupJob->disableSignals();
            }

            $backupJob->run();

            backupLogger()->comment('Backup completed!');

            return static::SUCCESS;
        } catch (Exception $exception) {
            if ($this->shouldRetry()) {
                if ($this->hasRetryDelay('backup')) {
                    $this->sleepFor($this->getRetryDelay('backup'));
                }

                $this->currentTry += 1;

                return $this->handle();
            }

            backupLogger()->error("Backup failed because: {$exception->getMessage()}.");

            report($exception);

            event(
                $exception instanceof BackupFailed
                    ? new BackupHasFailed($exception->getPrevious(), $exception->backupDestination?->diskName(), $exception->backupDestination?->backupName())
                    : new BackupHasFailed($exception)
            );

            return static::FAILURE;
        }
    }

    protected function guardAgainstInvalidOptions(): void
    {
        if (! $this->option('only-db')) {
            return;
        }

        if (! $this->option('only-files')) {
            return;
        }

        throw InvalidCommand::create('Cannot use `only-db` and `only-files` together');
    }
}
