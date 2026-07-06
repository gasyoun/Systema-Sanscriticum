<?php

namespace App\Console\Commands;

use App\Models\TelegramSupportAccount;
use Illuminate\Console\Command;

/**
 * Wind the Track B harvester's per-peer cursor back so the next
 * telegram-harvest:sync re-fetches history (recovery lane for messages that
 * were fetched but never stored). Pure DB operation — no MadelineProto, no
 * session, no lock; safe to run on any host at any time.
 *
 * Without options it just lists the stored cursors. Re-harvested messages are
 * APPENDED to the raw JSONL store, so anything already stored comes back as a
 * duplicate line — dedup is downstream in the corpus pipeline, keyed by
 * (telegram_chat_id, telegram_message_id).
 */
class RewindTelegramHarvestCursor extends Command
{
    protected $signature = 'telegram-harvest:rewind
        {--peer=* : Cursor key(s) to rewind — numeric telegram_chat_id from the no-option listing (repeatable)}
        {--to=0 : last_message_id to set (0 = re-fetch up to TELEGRAM_HARVEST_HISTORY_LIMIT)}
        {--all : Rewind every stored cursor}';

    protected $description = 'List or wind back the Track B harvester per-peer cursors (recovery before a re-sync).';

    public function handle(): int
    {
        $account = TelegramSupportAccount::query()
            ->where('name', (string) config('services.telegram_harvest.account_name', 'harvester'))
            ->first();

        $state = $account?->sync_state ?? [];
        $cursors = $state['peers'] ?? [];

        if ($account === null || $cursors === []) {
            $this->warn('No harvester cursors yet (telegram-harvest:sync has not recorded anything).');

            return self::SUCCESS;
        }

        $targets = $this->option('all')
            ? array_keys($cursors)
            : array_map(strval(...), (array) $this->option('peer'));

        if ($targets === []) {
            $this->table(
                ['peer (telegram_chat_id)', 'last_message_id', 'last_sent_at'],
                collect($cursors)
                    ->map(fn (array $c, string|int $key): array => [
                        (string) $key,
                        (int) ($c['last_message_id'] ?? 0),
                        (string) ($c['last_sent_at'] ?? ''),
                    ])
                    ->values()
                    ->all(),
            );
            $this->info('Rewind with: telegram-harvest:rewind --peer=<chat_id> [--to=<message_id>] (or --all).');

            return self::SUCCESS;
        }

        $to = max(0, (int) $this->option('to'));
        $changed = false;

        foreach ($targets as $key) {
            if (! array_key_exists($key, $cursors)) {
                $this->warn("Peer {$key}: no cursor — skipped (keys are numeric telegram_chat_id, see the no-option listing).");

                continue;
            }

            $current = (int) ($cursors[$key]['last_message_id'] ?? 0);
            if ($to >= $current) {
                // Rewind only — advancing the cursor forward would silently skip
                // messages the store never saw (the "199 burned" failure mode).
                $this->warn("Peer {$key}: cursor {$current} ≤ --to={$to} — nothing to rewind, skipped.");

                continue;
            }

            $state['peers'][$key]['last_message_id'] = $to;
            $changed = true;
            $this->info("Peer {$key}: cursor {$current} → {$to}.");
        }

        if ($changed) {
            $account->forceFill(['sync_state' => $state])->save();
            $this->comment('Next telegram-harvest:sync re-fetches past the wound-back cursor; already-stored messages are appended again (dedup downstream by chat_id+message_id).');
        }

        return self::SUCCESS;
    }
}
