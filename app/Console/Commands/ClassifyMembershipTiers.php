<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Tariff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ClassifyMembershipTiers extends Command
{
    protected $signature = 'membership:classify-tiers
                            {--apply : Write the explicit club code}
                            {--expected-memberships= : Required row count when applying}
                            {--expected-tariffs= : Required tariff count when applying}';

    protected $description = 'Dry-run and explicitly classify legacy H2644 rows without reading payment amounts';

    public function handle(): int
    {
        $course = Course::query()
            ->where('slug', (string) config('membership.club.course_slug', 'club'))
            ->first();

        $membershipCount = ClubMembership::query()->whereNull('tier_code')->count();
        $tariffQuery = Tariff::query()->whereNotNull('membership_months')->whereNull('membership_tier');
        if ($course instanceof Course) {
            $tariffQuery->where('course_id', $course->id);
        } else {
            $tariffQuery->whereRaw('1 = 0');
        }
        $tariffCount = $tariffQuery->count();

        $this->table(
            ['surface', 'unclassified', 'proposed tier', 'basis'],
            [
                ['club_memberships', $membershipCount, 'club', 'table existed only for H2644 Club periods'],
                ['tariffs', $tariffCount, 'club', 'club course + membership_months; amount ignored'],
            ],
        );

        if (! $this->option('apply')) {
            $this->info('DRY RUN — no rows changed. Re-run with both expected counts to apply.');

            return self::SUCCESS;
        }

        if ((bool) config('features.membership_tiered', false)) {
            $this->error('REFUSED: turn MEMBERSHIP_TIERED off before classifying.');

            return self::FAILURE;
        }

        if ((int) $this->option('expected-memberships') !== $membershipCount
            || (int) $this->option('expected-tariffs') !== $tariffCount) {
            $this->error('REFUSED: expected counts do not match this database; run dry first.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($course): void {
            ClubMembership::query()->whereNull('tier_code')->update(['tier_code' => MembershipTier::Club->value]);
            if ($course instanceof Course) {
                Tariff::query()
                    ->where('course_id', $course->id)
                    ->whereNotNull('membership_months')
                    ->whereNull('membership_tier')
                    ->update(['membership_tier' => MembershipTier::Club->value]);
            }
        });

        $this->info('APPLIED — explicit club codes written; payments and amounts unchanged.');

        return self::SUCCESS;
    }
}
