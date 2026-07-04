<?php

namespace App\Console\Commands;

use App\Services\TelegramHarvest\TelegramHarvestSyncService;
use Illuminate\Console\Command;

/**
 * D5 peer-discovery (Uprava/docs/DECISIONS_telegram_harvester.md). Read-only:
 * lists the account's groups/channels/DMs with id, username, type, access level
 * and the noforwards flag so the operator can pick which to put in
 * TELEGRAM_HARVEST_PEERS. No harvest, no store, no cursor advance.
 *
 * Uses the ONE shared MadelineProto session — never run concurrently with
 * telegram-support:sync or telegram-harvest:sync (one account, one session).
 */
class DiscoverTelegramHarvestPeers extends Command
{
    protected $signature = 'telegram-harvest:peers';

    protected $description = 'List the account dialogs (id | username | type | access_level | noforwards) for the Track B peer list. Read-only.';

    public function handle(TelegramHarvestSyncService $harvest): int
    {
        $peers = $harvest->discoverPeers();

        if ($peers === []) {
            $this->warn('No peers discovered (MadelineProto not configured on this host, or no dialogs).');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'username', 'type', 'access_level', 'noforwards', 'title'],
            array_map(static fn (array $p): array => [
                $p['id'],
                $p['username'] ? '@'.$p['username'] : '',
                $p['type'],
                $p['access_level'],
                $p['restricted'] ? 'yes' : '',
                $p['title'] ?? '',
            ], $peers),
        );

        $this->info(count($peers).' dialog(s). Copy the id/@username of the ones to harvest into TELEGRAM_HARVEST_PEERS.');

        return self::SUCCESS;
    }
}
