<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Learning\VisualDcsReleaseImporter;
use Illuminate\Console\Command;
use Throwable;

class VisualDcsRollbackCommand extends Command
{
    protected $signature = 'visualdcs:rollback';

    protected $description = 'Restore the previous promoted VisualDCS release (H2482)';

    public function handle(VisualDcsReleaseImporter $importer): int
    {
        try {
            $restored = $importer->rollback();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $restored) {
            $this->warn('No promoted release to roll back.');

            return self::SUCCESS;
        }

        $this->info('Restored '.$restored->release_id);

        return self::SUCCESS;
    }
}
