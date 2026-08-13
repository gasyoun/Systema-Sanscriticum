<?php

namespace App\Console\Commands;

use App\Console\Concerns\LocksMadelineSession;
use App\Services\TelegramHarvest\TelegramHarvestSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Track B harvester driver. Reuses the ONE shared MadelineProto session (the
 * support session) — NEVER run this concurrently with telegram-support:sync
 * (one account, one session; a second parallel session triggers AUTH_RESTART).
 */
class SyncTelegramHarvest extends Command
{
    use LocksMadelineSession;

    protected $signature = 'telegram-harvest:sync
        {--payload= : JSON file of pre-normalized harvest records (local import, no MadelineProto needed)}
        {--peer=* : Override the configured peer list (repeatable)}
        {--json : Emit one machine-readable JSON status object}';

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
            // Live path opens the shared MadelineProto session — serialise it
            // against telegram-support:sync / :peers (see LocksMadelineSession).
            $result = $this->withMadelineSessionLock(fn () => $harvest->sync($this->option('peer')));

            if ($result === null) {
                Log::warning('Telegram harvest skipped: MadelineProto session busy.');
                $this->emitStatus([
                    'status' => 'session_busy',
                    'retryable' => true,
                    'harvested' => 0,
                    'stored' => 0,
                    'skipped_dupe' => 0,
                    'failed' => 0,
                ]);

                return self::FAILURE;
            }
        }

        $this->emitStatus($result);

        return ($result['status'] ?? 'ok') === 'ok' && (int) ($result['failed'] ?? 0) === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function emitStatus(array $result): void
    {
        $safe = [
            'status' => (string) ($result['status'] ?? 'unknown'),
            'retryable' => (bool) ($result['retryable'] ?? false),
            'harvested' => (int) ($result['harvested'] ?? 0),
            'stored' => (int) ($result['stored'] ?? 0),
            'skipped_dupe' => (int) ($result['skipped_dupe'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($safe, JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('Telegram harvest: '.$safe['status']
            .'; harvested='.$safe['harvested']
            .'; stored='.$safe['stored']
            .'; skipped_dupe='.$safe['skipped_dupe']
            .'; failed='.$safe['failed']);
    }
}
