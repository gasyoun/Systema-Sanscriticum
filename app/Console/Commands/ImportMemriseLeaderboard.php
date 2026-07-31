<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MemriseLeaderboardImport;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * H2054 — import Memrise course leaderboard CSV into memrise_leaderboard_imports
 * and optionally link rows to users.memrise_username.
 *
 * CSV headers (flexible, case-insensitive):
 *   course_id, username, points [, period=all] [, rank]
 *
 *   php artisan leaderboard:import-memrise path/to.csv
 *   php artisan leaderboard:import-memrise path/to.csv --link
 *   php artisan leaderboard:import-memrise --link-only
 *   php artisan leaderboard:import-memrise path/to.csv --dry-run
 */
class ImportMemriseLeaderboard extends Command
{
    protected $signature = 'leaderboard:import-memrise
                            {path? : CSV path (course_id,username,points[,period][,rank])}
                            {--link : After import, attach user_id by users.memrise_username}
                            {--link-only : Only re-link existing rows (no CSV)}
                            {--dry-run : Report only, write nothing}';

    protected $description = 'Import Memrise leaderboard points CSV and optionally link by memrise_username';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $linkOnly = (bool) $this->option('link-only');
        $doLink = (bool) $this->option('link') || $linkOnly;

        if ($linkOnly) {
            return $this->linkRows($dry);
        }

        $path = (string) ($this->argument('path') ?? '');
        if ($path === '' || ! is_file($path)) {
            $this->error('CSV path required (or use --link-only). File not found: '.$path);

            return self::FAILURE;
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            $this->error("Cannot open {$path}");

            return self::FAILURE;
        }

        $header = fgetcsv($fh);
        if (! is_array($header) || $header === []) {
            fclose($fh);
            $this->error('Empty CSV');

            return self::FAILURE;
        }

        // Strip UTF-8 BOM from first header cell.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
        $map = $this->headerMap($header);
        foreach (['course_id', 'username', 'points'] as $req) {
            if (! isset($map[$req])) {
                fclose($fh);
                $this->error("CSV missing required column: {$req}. Got: ".implode(',', $header));

                return self::FAILURE;
            }
        }

        $upserted = 0;
        $skipped = 0;
        $now = now();

        while (($row = fgetcsv($fh)) !== false) {
            if ($this->rowEmpty($row)) {
                continue;
            }
            $courseId = trim((string) ($row[$map['course_id']] ?? ''));
            $username = trim((string) ($row[$map['username']] ?? ''));
            $points = (int) preg_replace('/[^\d]/', '', (string) ($row[$map['points']] ?? '0'));
            $period = isset($map['period'])
                ? strtolower(trim((string) ($row[$map['period']] ?? 'all')))
                : 'all';
            if ($period === '') {
                $period = 'all';
            }
            $rank = isset($map['rank']) && $row[$map['rank']] !== '' && $row[$map['rank']] !== null
                ? (int) $row[$map['rank']]
                : null;

            if ($courseId === '' || $username === '' || $points < 0) {
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->line("[dry-run] {$courseId} / {$username} = {$points} ({$period})");
                $upserted++;

                continue;
            }

            MemriseLeaderboardImport::query()->updateOrCreate(
                [
                    'course_id' => $courseId,
                    'username' => $username,
                    'period' => $period,
                ],
                [
                    'points' => $points,
                    'rank' => $rank,
                    'imported_at' => $now,
                ],
            );
            $upserted++;
        }
        fclose($fh);

        $this->info(($dry ? '[dry-run] would upsert ' : 'upserted ').$upserted.' row(s); skipped '.$skipped);

        if ($doLink) {
            return $this->linkRows($dry);
        }

        return self::SUCCESS;
    }

    private function linkRows(bool $dry): int
    {
        $linked = 0;
        $users = User::query()
            ->whereNotNull('memrise_username')
            ->where('memrise_username', '!=', '')
            ->get(['id', 'memrise_username']);

        foreach ($users as $user) {
            $uname = trim((string) $user->memrise_username);
            if ($uname === '') {
                continue;
            }

            $q = MemriseLeaderboardImport::query()
                ->whereRaw('LOWER(username) = ?', [mb_strtolower($uname)]);

            $count = (clone $q)->where(function ($w) use ($user) {
                $w->whereNull('user_id')->orWhere('user_id', '!=', $user->id);
            })->count();

            if ($count === 0) {
                continue;
            }

            if ($dry) {
                $this->line("[dry-run] link {$uname} → user #{$user->id} ({$count} row(s))");
            } else {
                $q->update(['user_id' => $user->id]);
            }
            $linked += $count;
        }

        $this->info(($dry ? '[dry-run] would link ' : 'linked ').$linked.' import row(s)');

        return self::SUCCESS;
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $aliases = [
            'course_id' => ['course_id', 'course', 'courseid', 'id'],
            'username' => ['username', 'user', 'name', 'memrise_username', 'nick'],
            'points' => ['points', 'score', 'total', 'xp', 'очки'],
            'period' => ['period', 'timeframe', 'span'],
            'rank' => ['rank', 'position', 'place', 'место'],
        ];

        $map = [];
        foreach ($header as $i => $col) {
            $norm = mb_strtolower(trim((string) $col));
            $norm = str_replace([' ', '-'], '_', $norm);
            foreach ($aliases as $canonical => $list) {
                if (in_array($norm, $list, true) && ! isset($map[$canonical])) {
                    $map[$canonical] = $i;
                }
            }
        }

        return $map;
    }

    /** @param  list<string|null>  $row */
    private function rowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
