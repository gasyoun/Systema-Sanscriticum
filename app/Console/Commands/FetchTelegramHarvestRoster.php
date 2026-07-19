<?php

namespace App\Console\Commands;

use App\Console\Concerns\LocksMadelineSession;
use App\Services\TelegramHarvest\RosterStoreWriter;
use App\Services\TelegramHarvest\TelegramHarvestSyncService;
use Illuminate\Console\Command;

/**
 * D9 (Track C, Uprava/docs/DECISIONS_telegram_harvester.md): full member
 * roster for one peer via the shared personal-account MadelineProto session.
 * Read-mostly (writes only the out-of-git roster snapshot, no cursor, no
 * corpus store touch) — still serialised against the sync commands (one
 * account, one session).
 */
class FetchTelegramHarvestRoster extends Command
{
    use LocksMadelineSession;

    protected $signature = 'telegram-harvest:roster {peer : @username or numeric chat id}';

    protected $description = 'Snapshot the full member roster of one Track B peer (out-of-git). Requires the personal-account session.';

    public function handle(TelegramHarvestSyncService $harvest): int
    {
        $peer = (string) $this->argument('peer');

        $roster = $this->withMadelineSessionLock(fn () => $harvest->fetchRoster($peer));

        if ($roster === null) {
            $this->warn('Telegram harvest roster: session_busy (another MadelineProto command holds the session). Retry shortly.');

            return self::SUCCESS;
        }

        if ($roster === []) {
            $this->warn('No roster returned (MadelineProto not configured, peer not a group, or empty chat).');

            return self::SUCCESS;
        }

        $path = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
        $file = (new RosterStoreWriter($path))->write($peer, $roster);

        $this->info(count($roster)." member(s) written to {$file}.");

        return self::SUCCESS;
    }
}
