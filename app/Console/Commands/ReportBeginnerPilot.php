<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\Reports\BeginnerPilotReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ReportBeginnerPilot extends Command
{
    protected $signature = 'report:beginner-pilot {--from= : Verified pilot start, YYYY-MM-DD} {--days=30 : Observation window, 1-366 days} {--main-course=* : Verified main-course ID; repeat for each course} {--json : Emit aggregate JSON}';

    protected $description = 'Read-only first-purchase pilot report; no personal data and no automatic pilot launch';

    public function handle(BeginnerPilotReport $report): int
    {
        $raw = (string) $this->option('from');
        $days = (string) $this->option('days');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) || ! ctype_digit($days) || (int) $days < 1 || (int) $days > 366) {
            $this->error('Supply --from=YYYY-MM-DD and --days=1..366. This command does not launch the pilot.');
            return self::FAILURE;
        }
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $raw, config('app.timezone'));
        } catch (\Throwable) {
            $from = null;
        }
        if (! $from || $from->format('Y-m-d') !== $raw || $from->isFuture()) {
            $this->error('The start must be a valid date no later than today.');
            return self::FAILURE;
        }
        $ids = $this->option('main-course');
        foreach ($ids as $id) {
            if (! ctype_digit((string) $id) || (int) $id < 1 || ! Course::query()->whereKey((int) $id)->exists()) {
                $this->error('Every --main-course must identify an existing, verified main course.');
                return self::FAILURE;
            }
        }
        $result = $report->build($from, (int) $days, array_values(array_unique(array_map('intval', $ids))));
        if (! $this->option('json')) {
            $this->info('Beginner pilot — aggregate read-only report. Null means unavailable, not zero.');
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
