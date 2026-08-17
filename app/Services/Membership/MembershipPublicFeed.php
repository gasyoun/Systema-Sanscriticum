<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\Group;
use App\Models\Tariff;
use Illuminate\Support\Collection;

final class MembershipPublicFeed
{
    /** @return array{schema:string,version:string,window:array<string,string>,offers:list<array<string,mixed>>} */
    public function build(): array
    {
        $offers = $this->membershipOffers()
            ->concat($this->autumnCourseOffers())
            ->sortBy(fn (array $offer): string => $offer['starts_on'].'|'.str_pad((string) $offer['position'], 6, '0', STR_PAD_LEFT).'|'.$offer['id'])
            ->values()
            ->all();

        return [
            'schema' => 'systema.membership-commerce-feed.v1',
            'version' => (string) config('membership.public_feed.version', '2026-08-17.1'),
            'window' => [
                'starts_on' => (string) config('membership.public_feed.window_start', '2026-09-01'),
                'ends_on' => (string) config('membership.public_feed.window_end', '2026-10-31'),
            ],
            'offers' => $offers,
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function membershipOffers(): Collection
    {
        $course = Course::query()->where('slug', (string) config('membership.club.course_slug', 'club'))->first();
        if (! $course) {
            return collect();
        }

        return $course->tariffs()
            ->where('is_active', true)
            ->whereNotNull('membership_months')
            ->get()
            ->filter(fn (Tariff $tariff): bool => $tariff->membership_tier instanceof MembershipTier)
            ->reject(fn (Tariff $tariff): bool => $tariff->membership_tier === MembershipTier::Top
                && ! (bool) config('features.membership_top', false))
            ->map(fn (Tariff $tariff): array => $this->offer(
                id: 'membership-'.$tariff->membership_tier->value.'-'.$tariff->membership_months.'m',
                kind: 'membership',
                title: $tariff->title ?: match ($tariff->membership_tier) {
                    MembershipTier::Free => 'Свободный',
                    MembershipTier::Basic => 'Основной',
                    MembershipTier::Club => 'Клуб',
                    MembershipTier::Top => 'Верхний',
                },
                course: $course,
                tariff: $tariff,
                startsOn: (string) config('membership.public_feed.membership_launch_date', '2026-09-01'),
                status: 'scheduled',
                position: $tariff->membership_tier->rank() * 100 + (int) $tariff->membership_months,
            ));
    }

    /** @return Collection<int,array<string,mixed>> */
    private function autumnCourseOffers(): Collection
    {
        $start = (string) config('membership.public_feed.window_start', '2026-09-01');
        $end = (string) config('membership.public_feed.window_end', '2026-10-31');
        $excluded = PrivateArchiveEligibility::privateOfferSlugs();

        return Course::query()
            ->where('is_visible', true)
            ->where('is_active', true)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('slug', $excluded))
            ->whereHas('groups', fn ($query) => $query
                ->whereIn('status', ['forming', 'active'])
                ->where(function ($dates) use ($start, $end): void {
                    $dates->whereBetween('start_date_override', [$start, $end])
                        ->orWhere(function ($planned) use ($start, $end): void {
                            $planned->whereNull('start_date_override')
                                ->whereBetween('planned_start_date', [$start, $end]);
                        });
                }))
            ->with(['groups' => fn ($query) => $query->whereIn('status', ['forming', 'active']), 'tariffs' => fn ($query) => $query
                ->where('is_active', true)
                ->whereNull('membership_months')
                ->orderBy('price')])
            ->get()
            ->flatMap(function (Course $course): Collection {
                $group = $course->groups
                    ->filter(fn (Group $candidate): bool => $candidate->effectiveStartDate() !== null)
                    ->sortBy(fn (Group $candidate): string => $candidate->effectiveStartDate()?->toDateString() ?? '9999-12-31')
                    ->first();

                if (! $group) {
                    return collect();
                }

                return $course->tariffs->map(fn (Tariff $tariff): array => $this->offer(
                    id: 'course-'.$course->slug.'-'.$tariff->id,
                    kind: 'course',
                    title: $course->title,
                    course: $course,
                    tariff: $tariff,
                    startsOn: $group->effectiveStartDate()->toDateString(),
                    status: $group->status === 'active' ? 'active' : 'forming',
                    position: (int) $course->id,
                ));
            });
    }

    /** @return array<string,mixed> */
    private function offer(
        string $id,
        string $kind,
        string $title,
        Course $course,
        Tariff $tariff,
        string $startsOn,
        string $status,
        int $position,
    ): array {
        $base = route('checkout.show', $tariff);

        return [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'course_slug' => $course->slug,
            'tariff_id' => (int) $tariff->id,
            'tier' => $tariff->membership_tier?->value,
            'term_months' => $tariff->membership_months,
            'starts_on' => $startsOn,
            'status' => $status,
            'price_rub' => (int) round((float) $tariff->price),
            'checkout_urls' => [
                'samskrte' => $base.'?source_site=samskrte&campaign=autumn-2026',
                'samskrtam' => $base.'?source_site=samskrtam&campaign=autumn-2026',
            ],
            'position' => $position,
        ];
    }
}
