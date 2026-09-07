<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\TeacherStoryProgramSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Seed the 4-week «Наши учителя» + «Истории учеников» program
 * (H4313, program wave W4): 8 teacher_spotlight + 4 student_story slots,
 * each mirrored into story_posts (lane=channel). Flag-gated like the
 * rest of the calendar; publish itself stays behind content_calendar_autopilot
 * (default OFF) — nothing here touches live VK/TG.
 */
class SeedTeacherStoryProgramCommand extends Command
{
    protected $signature = 'content:seed-teacher-story
                            {start? : YYYY-MM-DD Monday of the first week (default: next Monday)}
                            {--force-flag : Run even when CONTENT_CALENDAR_ENABLED is false (for tests/CI)}';

    protected $description = 'Seed 4-week teacher-spotlight + student-story calendar program (2 teachers + 1 story per week)';

    public function handle(TeacherStoryProgramSeeder $seeder): int
    {
        if (! config('features.content_calendar') && ! $this->option('force-flag')) {
            $this->warn('content_calendar flag is OFF — pass --force-flag to seed anyway, or enable CONTENT_CALENDAR_ENABLED.');

            return self::SUCCESS;
        }

        $startArg = (string) ($this->argument('start') ?? '');
        if ($startArg === '') {
            $start = now('Europe/Moscow')->next(Carbon::MONDAY);
            $startArg = $start->toDateString();
        } elseif (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $startArg, $m)) {
            $this->error('start must be YYYY-MM-DD');

            return self::FAILURE;
        }

        try {
            $result = $seeder->seed((int) substr($startArg, 0, 4), (int) substr($startArg, 5, 2), (int) substr($startArg, 8, 2));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'teacher-story program seed (from %s): created=%d mirrored_to_story_posts=%d skipped=%d',
            $startArg,
            $result['created'],
            $result['mirrored'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
