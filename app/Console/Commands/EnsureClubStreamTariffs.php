<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Membership\ClubEntitlement;
use App\Services\Membership\ClubStreamTariffCatalog;
use Illuminate\Console\Command;

/**
 * Idempotent Club 1/3/12 D20 tariff rows on the membership course (H3648).
 * Default dry-run. Does not call Tochka. Does not flip MEMBERSHIP_CLUB_STREAMS_ONLY.
 */
class EnsureClubStreamTariffs extends Command
{
    protected $signature = 'membership:ensure-club-stream-tariffs
        {--apply : write missing inactive (flag OFF) or active (flag ON) Club 1/3/12 rows}';

    protected $description = 'Club Course + 1/3/12 D20 tariffs (H3648). Dry-run default; no live charge.';

    public function handle(ClubEntitlement $entitlement, ClubStreamTariffCatalog $catalog): int
    {
        $course = $entitlement->clubCourse();
        if ($course === null) {
            $this->error('No course with slug «'.config('membership.club.course_slug').'».');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $rows = $catalog->ensure($course, $apply);
        $existing = $catalog->existingOn($course);

        $this->info('course #'.$course->id.' «'.$course->title.'» flag='.($catalog->enabled() ? 'ON' : 'OFF'));
        $this->info('D20 Club 1/3/12 rows: '.$existing->count().($apply ? ' after apply' : ' (dry-run)'));
        foreach ($apply ? $rows : $existing as $tariff) {
            $this->line('  tariff #'.$tariff->id.' '.$tariff->title
                .' '.$tariff->price.'₽ months='.$tariff->membership_months
                .' active='.($tariff->is_active ? 'yes' : 'no')
                .' key='.$tariff->accessKey());
        }

        if (! $apply) {
            $this->comment('No writes. --apply creates/aligns rows; is_active follows MEMBERSHIP_CLUB_STREAMS_ONLY.');
        }

        return self::SUCCESS;
    }
}
