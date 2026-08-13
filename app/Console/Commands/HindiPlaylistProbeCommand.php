<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HindiProgrammePlaylist;
use Illuminate\Console\Command;

/**
 * H2441 — read-only Hindi playlist counts (prod smoke for user 6494).
 *
 * Does not write payments, groups, or grants. Works with the flag OFF so
 * ops can count before flipping HINDI_PROGRAMME_PLAYLIST.
 */
class HindiPlaylistProbeCommand extends Command
{
    protected $signature = 'hindi:playlist-probe
        {user : User id}
        {--json : Print JSON}';

    protected $description = 'Read-only Hindi programme playlist counts for one user';

    public function handle(HindiProgrammePlaylist $playlist): int
    {
        $user = User::query()->find((int) $this->argument('user'));
        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $report = $playlist->probe($user);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('user_id='.$report['user_id'].' flag='.($report['flag'] ? 'on' : 'off'));
        $this->table(
            ['id', 'slug', 'label', 'published', 'accessible'],
            array_map(static fn (array $row): array => [
                $row['id'],
                $row['slug'],
                $row['label'],
                $row['published'],
                $row['accessible'],
            ], $report['shells']),
        );
        $this->info('accessible_total='.$report['accessible_total']);

        return self::SUCCESS;
    }
}
