<?php

namespace App\Console\Commands;

use App\Console\Concerns\LocksMadelineSession;
use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncPhase;
use App\Services\Telegram\MadelineSyncWatchdog;
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

    public function handle(
        TelegramHarvestSyncService $harvest,
        MadelineSyncWatchdog $watchdog,
        MadelineSessionReaper $reaper,
    ): int {
        if ($guardFailure = $this->guardStoreOwnership()) {
            $this->error($guardFailure);
            Log::error('Telegram harvest sync refused: store ownership guard failed.', ['reason' => $guardFailure]);

            return self::FAILURE;
        }

        $payloadPath = $this->option('payload');
        $accountName = (string) config('services.telegram_harvest.account_name', 'harvester');

        try {
            return $this->runSync($payloadPath, $accountName, $harvest, $watchdog, $reaper);
        } finally {
            $this->reclaimStoreOwnershipIfRoot();
        }
    }

    private function runSync(
        ?string $payloadPath,
        string $accountName,
        TelegramHarvestSyncService $harvest,
        MadelineSyncWatchdog $watchdog,
        MadelineSessionReaper $reaper,
    ): int {
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
            $timeout = (int) config('services.telegram_harvest.sync_timeout_seconds', 120);
            $cooldown = (int) config('services.telegram_harvest.sync_timeout_cooldown_seconds', 600);

            // H3411: the harvester shares the default MadelineProto session/phase
            // keys with telegram-support:sync (MadelineSessionContext, D1 — one
            // account, one session). A post-timeout cooldown armed by EITHER
            // command must block the other's next attempt on the same session.
            if ($cooldown > 0 && MadelineSyncPhase::cooldownActive()) {
                Log::warning('Telegram harvest sync skipped: post-timeout cooldown', [
                    'cooldown_seconds' => $cooldown,
                    'last_phase' => MadelineSyncPhase::current(),
                ]);
                $this->emitStatus([
                    'status' => 'post_timeout_cooldown',
                    'retryable' => true,
                    'harvested' => 0,
                    'stored' => 0,
                    'skipped_dupe' => 0,
                    'failed' => 0,
                ]);

                return self::SUCCESS;
            }

            // Live path opens the shared MadelineProto session — serialise it
            // against telegram-support:sync / :peers (see LocksMadelineSession).
            // The timeout ceiling mirrors SyncTelegramSupport (H1915: cleanup
            // must run inside the watchdog's SIGALRM handler, right before
            // exit() — nothing here survives to run a finally/catch).
            $armed = $watchdog->arm($timeout, fn (int $seconds) => $this->cleanUpAfterTimeout($reaper, $seconds, $accountName));

            if (! $armed && $timeout > 0) {
                Log::error('Telegram harvest sync running WITHOUT a time ceiling: watchdog did not arm (pcntl extension missing).', [
                    'timeout_seconds' => $timeout,
                    'account' => $accountName,
                ]);
                $this->warn('Watchdog unavailable (pcntl extension missing) — running without a time ceiling.');
            }

            try {
                $result = $this->withMadelineSessionLock(fn () => $harvest->sync($this->option('peer')));
            } finally {
                $watchdog->disarm();
            }

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

    /**
     * H3411: 19-24-08-2026 a manually root-invoked run left ors_faq_peers.json
     * root:root 640 under store_path — both daily www-data cron runs then hit
     * Permission-denied until a human ran `chown -R www-data storage/app/telegram-harvest`
     * (docs/SERVER_SOFT_ALERT_PLAYBOOK.md incident log). Fail loud instead of
     * limping into the same silent multi-day skip.
     */
    private function guardStoreOwnership(): ?string
    {
        if (! function_exists('posix_geteuid')) {
            return null;
        }

        $storeDir = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
        if (! File::isDirectory($storeDir)) {
            return null;
        }

        if (posix_geteuid() === 0) {
            return null; // root may proceed; reclaimStoreOwnershipIfRoot() fixes ownership after
        }

        $storeOwnerUid = fileowner($storeDir);
        $badFile = collect(File::allFiles($storeDir))
            ->first(fn ($file) => fileowner($file->getPathname()) !== $storeOwnerUid);

        if ($badFile === null) {
            return null;
        }

        return sprintf(
            'Refusing to run: %s is owned by a different user (uid %d) than %s (uid %d) — '
            .'a previous root-invoked run likely left root-owned files behind (H3411, '
            .'ors_faq_peers.json 19-24-08-2026). Fix with: sudo chown -R %s:%s %s',
            $badFile->getPathname(),
            fileowner($badFile->getPathname()),
            $storeDir,
            $storeOwnerUid,
            config('services.telegram_harvest.expected_owner', 'www-data'),
            config('services.telegram_harvest.expected_group', 'www-data'),
            $storeDir
        );
    }

    /**
     * Mirror image of guardStoreOwnership(): if this invocation ran as root,
     * hand the store dir back to expected_owner:expected_group so the next
     * www-data cron run doesn't inherit root-owned files.
     */
    private function reclaimStoreOwnershipIfRoot(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $storeDir = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
        if (! File::isDirectory($storeDir)) {
            return;
        }

        $owner = (string) config('services.telegram_harvest.expected_owner', 'www-data');
        $group = (string) config('services.telegram_harvest.expected_group', 'www-data');

        $output = [];
        exec(sprintf('chown -R %s:%s %s 2>&1', escapeshellarg($owner), escapeshellarg($group), escapeshellarg($storeDir)), $output, $exitCode);

        if ($exitCode !== 0) {
            Log::error('Telegram harvest sync: post-run chown to '.$owner.':'.$group.' failed — state dir may be left root-owned.', [
                'store_dir' => $storeDir,
                'output' => $output,
            ]);
            $this->warn('WARNING: ran as root and failed to hand ownership of '.$storeDir.' back to '.$owner.' — next www-data run may hit Permission denied.');

            return;
        }

        Log::warning('Telegram harvest sync ran as root — reclaimed ownership of state dir to '.$owner.':'.$group.' after completion.', [
            'store_dir' => $storeDir,
        ]);
    }

    /**
     * Run inside the watchdog's SIGALRM handler, right before exit() — the only
     * chance to clean up (mirrors SyncTelegramSupport::cleanUpAfterTimeout).
     * Order: session lock first (held 900s — left hanging blocks ~15 minutes of
     * follow-on runs per timeout), then the session daemon, then the DB trail.
     */
    private function cleanUpAfterTimeout(MadelineSessionReaper $reaper, int $seconds, string $accountName): void
    {
        $this->releaseMadelineSessionLock();

        $killed = $reaper->killDaemons();
        $removed = $reaper->clearIpcArtifacts();

        $cooldown = (int) config('services.telegram_harvest.sync_timeout_cooldown_seconds', 600);
        MadelineSyncPhase::armCooldown($cooldown);

        Log::error('Telegram harvest sync timed out — process stopped by watchdog', [
            'timeout_seconds' => $seconds,
            'account' => $accountName,
            'killed_processes' => $killed,
            'removed_files' => $removed,
            'phase' => MadelineSyncPhase::current(),
            'cooldown_seconds' => $cooldown,
        ]);

        TelegramSupportAccount::query()
            ->where('name', $accountName)
            ->update([
                'last_synced_at' => now(),
                'last_sync_error' => "Run aborted on timeout ({$seconds}s); session daemon reset.",
            ]);

        $this->error("Telegram harvest sync: timeout after {$seconds}s — process stopped, session daemon reset.");
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
