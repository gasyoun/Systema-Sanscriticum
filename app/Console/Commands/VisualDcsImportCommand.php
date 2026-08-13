<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Learning\VisualDcsReleaseImporter;
use Illuminate\Console\Command;
use Throwable;

class VisualDcsImportCommand extends Command
{
    protected $signature = 'visualdcs:import
                            {path : Directory with manifest.json + payloads (H2481 contract)}
                            {--no-promote : Stage only, do not swap the live catalog}';

    protected $description = 'Verify and pin a VisualDCS learner-contract release (H2482)';

    public function handle(VisualDcsReleaseImporter $importer): int
    {
        try {
            $result = $importer->import(
                (string) $this->argument('path'),
                promote: ! (bool) $this->option('no-promote'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $release = $result['release'];
        if ($result['noop']) {
            $this->info('Already promoted '.$release->release_id.' — no-op.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Imported %s (%s) status=%s hash=%s',
            $release->release_id,
            $release->contract_version,
            $release->status,
            $release->manifest_hash,
        ));

        return self::SUCCESS;
    }
}
