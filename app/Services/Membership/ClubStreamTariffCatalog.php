<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\Tariff;
use Illuminate\Support\Collection;

/**
 * One Club course + three D19/D20 tariffs (1 / 3 / 12 months, 0/5/15 %).
 *
 * Does not touch live ₽1 500 Club checkout rows: those have different prices.
 * New rows stay inactive while MEMBERSHIP_CLUB_STREAMS_ONLY is false.
 */
final class ClubStreamTariffCatalog
{
    public function enabled(): bool
    {
        return (bool) config('features.membership_club_streams_only', false);
    }

    /**
     * @return Collection<int, Tariff>
     */
    public function existingOn(Course $course): Collection
    {
        return Tariff::query()
            ->where('course_id', $course->id)
            ->where('membership_tier', MembershipTier::Club)
            ->whereIn('membership_months', [1, 3, 12])
            ->get()
            ->filter(fn (Tariff $tariff): bool => $tariff->hasExpectedMembershipPrice())
            ->values();
    }

    /**
     * @return Collection<int, Tariff>
     */
    public function ensure(Course $course, bool $persist = true): Collection
    {
        $active = $this->enabled();
        $rows = collect();

        foreach ([1, 3, 12] as $months) {
            $price = MembershipTier::Club->priceForTerm($months);
            $existing = Tariff::query()
                ->where('course_id', $course->id)
                ->where('membership_tier', MembershipTier::Club)
                ->where('membership_months', $months)
                ->get()
                ->first(fn (Tariff $tariff): bool => $tariff->hasExpectedMembershipPrice());

            if ($existing instanceof Tariff) {
                if ($persist && (bool) $existing->is_active !== $active) {
                    $existing->is_active = $active;
                    $existing->save();
                }
                $rows->push($existing);

                continue;
            }

            if (! $persist) {
                continue;
            }

            $rows->push(Tariff::create([
                'course_id' => $course->id,
                'title' => 'Клуб — '.$months.' мес',
                'type' => 'full',
                'price' => $price,
                'membership_months' => $months,
                'membership_tier' => MembershipTier::Club,
                'is_active' => $active,
            ]));
        }

        return $rows;
    }
}
