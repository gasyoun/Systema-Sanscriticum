<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\SystemaCalendarBridge;
use Illuminate\Console\Command;

/**
 * Bridge live Systema signals (free lecture clips, upcoming class schedule)
 * into ContentCalendarSlot rows for YYYY-MM (H1566, content calendar W3).
 */
class BridgeSystemaContentCommand extends Command
{
    protected $signature = 'content:bridge-systema
                            {month : YYYY-MM}
                            {--force-flag : Run even when CONTENT_CALENDAR_ENABLED is false (for tests/CI)}';

    protected $description = 'Bridge free lecture clips and upcoming class schedule into calendar slots';

    public function handle(SystemaCalendarBridge $bridge): int
    {
        if (! config('features.content_calendar') && ! $this->option('force-flag')) {
            $this->warn('content_calendar flag is OFF — pass --force-flag to bridge anyway, or enable CONTENT_CALENDAR_ENABLED.');

            return self::SUCCESS;
        }

        $monthArg = (string) $this->argument('month');
        if (! preg_match('/^(\d{4})-(\d{2})$/', $monthArg, $m)) {
            $this->error('month must be YYYY-MM');

            return self::FAILURE;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            $this->error('invalid month');

            return self::FAILURE;
        }

        $clips = $bridge->bridgeClips($year, $month);
        $digest = $bridge->bridgeScheduleDigest($year, $month);

        $this->info(sprintf(
            'bridge-systema %s: clips_filled=%d clips_remaining_empty=%d schedule_digest_created=%s courses=%d',
            $monthArg,
            $clips['filled'],
            $clips['remaining_empty'],
            $digest['created'] ? 'yes' : 'no',
            $digest['course_count'],
        ));

        return self::SUCCESS;
    }
}
