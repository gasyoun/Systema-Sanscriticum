<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\ContentMonthSeeder;
use Illuminate\Console\Command;

/**
 * Seed ContentCalendarSlot rows for YYYY-MM (H1564).
 */
class SeedContentMonthCommand extends Command
{
    protected $signature = 'content:seed-month
                            {month : YYYY-MM}
                            {--path= : vk-ors CSV directory}
                            {--force-flag : Run even when CONTENT_CALENDAR_ENABLED is false (for tests/CI)}';

    protected $description = 'Seed content calendar slots for a month from vk-ors density';

    public function handle(ContentMonthSeeder $seeder): int
    {
        if (! config('features.content_calendar') && ! $this->option('force-flag')) {
            $this->warn('content_calendar flag is OFF — pass --force-flag to seed anyway, or enable CONTENT_CALENDAR_ENABLED.');

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

        try {
            $result = $seeder->seed($year, $month, $this->option('path') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'seed %s: created=%d existing=%d target=%d',
            $monthArg,
            $result['created'],
            $result['skipped'],
            $result['target'],
        ));

        return self::SUCCESS;
    }
}
