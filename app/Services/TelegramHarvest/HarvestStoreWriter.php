<?php

namespace App\Services\TelegramHarvest;

use Illuminate\Support\Facades\File;

/**
 * Appends harvested messages to the OUT-OF-GIT raw store (D2).
 *
 * Raw text + PII never enter the Systema repo. This writer targets
 * services.telegram_harvest.store_path, which points at the corpus repo's
 * gitignored data/raw/ directory (or an external workspace). The corpus repo's
 * Python tooling turns this raw JSONL into the committed, normalized +
 * anonymized derived artifacts.
 *
 * Layout: {store_path}/{lane}/{peerKey}/{YYYY-MM-DD}.jsonl
 *   lane = "archive"  → DMs (personal archive only, never published)
 *          "corpus"   → groups/channels (candidate for a gated derived release)
 */
class HarvestStoreWriter
{
    public function __construct(private readonly string $storePath) {}

    /**
     * @param  array<string, mixed>  $record  a normalized harvest record
     */
    public function write(array $record): string
    {
        $lane = ($record['access_level'] ?? 'corpus') === 'dm' ? 'archive' : 'corpus';
        $peerKey = (string) ($record['telegram_chat_id'] ?? 'unknown');
        $date = substr((string) ($record['sent_at'] ?? ''), 0, 10) ?: 'undated';

        $dir = $this->storePath.'/'.$lane.'/'.$peerKey;
        File::ensureDirectoryExists($dir);

        $file = $dir.'/'.$date.'.jsonl';
        File::append($file, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        return $file;
    }
}
