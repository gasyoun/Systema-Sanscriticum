<?php

namespace App\Services\TelegramHarvest;

use Illuminate\Support\Facades\File;

/**
 * D9 (Track C): writes a full-snapshot roster (overwritten each run, not
 * appended — a roster is "who's in the chat right now", not a log) to the
 * SAME out-of-git store as the harvested messages.
 *
 * Layout: {store_path}/roster/{peerKey}.json
 */
class RosterStoreWriter
{
    public function __construct(private readonly string $storePath) {}

    /**
     * @param  array<int, array{id: ?int, username: ?string, name: ?string, is_bot: bool}>  $roster
     */
    public function write(string $peerKey, array $roster): string
    {
        $dir = $this->storePath.'/roster';
        File::ensureDirectoryExists($dir);

        $file = $dir.'/'.$peerKey.'.json';
        File::put($file, json_encode([
            'peer' => $peerKey,
            'fetched_at' => now()->toIso8601String(),
            'count' => count($roster),
            'members' => $roster,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $file;
    }
}
