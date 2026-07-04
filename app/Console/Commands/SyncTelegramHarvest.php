<?php

namespace App\Console\Commands;

use App\Services\TelegramHarvest\TelegramHarvestSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Track B harvester driver. Reuses the ONE shared MadelineProto session (the
 * support session) — NEVER run this concurrently with telegram-support:sync
 * (one account, one session; a second parallel session triggers AUTH_RESTART).
 */
class SyncTelegramHarvest extends Command
{
    protected $signature = 'telegram-harvest:sync
        {--payload= : JSON file of pre-normalized harvest records (local import, no MadelineProto needed)}
        {--peer=* : Override the configured peer list (repeatable)}';

    protected $description = 'Harvest Sanskrit groups/channels/DMs into the out-of-git corpus store (Track B).';

    public function handle(TelegramHarvestSyncService $harvest): int
    {
        $payloadPath = $this->option('payload');

        if ($payloadPath) {
            if (! File::exists($payloadPath)) {
                $this->error("Payload file not found: {$payloadPath}");

                return self::FAILURE;
            }

            $payload = json_decode(File::get($payloadPath), true);
            if (! is_array($payload)) {
                $this->error('Payload must be a JSON array of normalized records.');

                return self::FAILURE;
            }

            $result = $harvest->ingestNormalized($payload);
            $result['status'] ??= 'ok';
        } else {
            $result = $harvest->sync($this->option('peer'));
        }

        $line = 'Telegram harvest: '.$result['status']
            .'; harvested='.($result['harvested'] ?? 0)
            .'; stored='.($result['stored'] ?? 0)
            .'; skipped_dupe='.($result['skipped_dupe'] ?? 0)
            .'; failed='.($result['failed'] ?? 0);
        if (! empty($result['error'])) {
            $line .= '; error='.$result['error'];
        }

        $this->info($line);

        return self::SUCCESS;
    }
}
