<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * H3314 — prune dead Sanctum personal access tokens.
 *
 * Deletes rows that can no longer authenticate:
 *  - tokens whose explicit `expires_at` has passed (new mobile tokens carry
 *    a 90-day expires_at since H3314);
 *  - legacy tokens without `expires_at` older than the configured
 *    `sanctum.expiration` window — they are already rejected by the guard,
 *    this only keeps the table from growing forever.
 */
class PruneAuthTokens extends Command
{
    protected $signature = 'tokens:prune-expired {--hours=0 : Only prune rows expired/aged at least this many hours ago}';

    protected $description = 'Prune expired Sanctum personal access tokens (H3314)';

    public function handle(): int
    {
        $now = Carbon::now();
        $graceHours = max(0, (int) $this->option('hours'));
        $expiresCut = $now->copy()->subHours($graceHours);
        $windowMinutes = (int) config('sanctum.expiration', 0);
        $windowCut = $windowMinutes > 0
            ? $now->copy()->subMinutes($windowMinutes)->subHours($graceHours)
            : null;

        $query = DB::table('personal_access_tokens')
            ->where(function ($q) use ($expiresCut) {
                $q->whereNotNull('expires_at')->where('expires_at', '<', $expiresCut);
            });

        if ($windowCut !== null) {
            $query->orWhere(function ($q) use ($windowCut) {
                $q->whereNull('expires_at')->where('created_at', '<', $windowCut);
            });
        }

        $deleted = $query->delete();

        $this->info("Pruned {$deleted} expired Sanctum token(s).");

        return self::SUCCESS;
    }
}
