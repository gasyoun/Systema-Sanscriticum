<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;

/**
 * H2482 — VisualDCS entitlement over existing course/tariff payments.
 *
 * Never reads a contract `tier` as payment state. Preview is public (bounded
 * catalog records). Full / attested-tier access requires an access-granting
 * payment or an admin-like role. Impersonation sees the impersonated user.
 */
final class VisualDcsEntitlement
{
    public const PREVIEW = 'preview';

    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const FULL = 'full';

    public const EXPIRED = 'expired';

    public const ADMIN = 'admin';

    public static function matrixState(?User $user): string
    {
        if ($user && $user->isAdminLike()) {
            return self::ADMIN;
        }

        if (! $user) {
            return self::PREVIEW;
        }

        $slugs = self::configuredSlugs();
        $paidSlugs = self::paidCourseSlugs($user);

        if ($slugs === []) {
            if ($paidSlugs !== []) {
                return self::FULL;
            }

            return self::hasHistoricPaid($user) ? self::EXPIRED : self::UNPAID;
        }

        $overlap = array_values(array_intersect($slugs, $paidSlugs));
        if ($overlap === []) {
            return self::hasHistoricPaid($user) ? self::EXPIRED : self::UNPAID;
        }

        return count($overlap) === count($slugs) ? self::FULL : self::PARTIAL;
    }

    public static function hasFullAccess(?User $user): bool
    {
        $state = self::matrixState($user);

        return in_array($state, [self::FULL, self::PARTIAL, self::ADMIN], true);
    }

    public static function canSeeAttested(?User $user): bool
    {
        return self::hasFullAccess($user);
    }

    /**
     * @return list<string>
     */
    public static function configuredSlugs(): array
    {
        $slugs = config('visualdcs.entitlement_course_slugs', []);

        return is_array($slugs) ? array_values(array_filter($slugs, 'is_string')) : [];
    }

    /**
     * @return list<string>
     */
    public static function paidCourseSlugs(User $user): array
    {
        $query = Payment::query()
            ->where('user_id', $user->id)
            ->paid()
            ->real()
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout']);

        $slugs = self::configuredSlugs();
        if ($slugs !== []) {
            $ids = Course::query()->whereIn('slug', $slugs)->pluck('id');
            $query->whereIn('course_id', $ids);
        }

        $courseIds = $query->pluck('course_id')->unique()->filter()->all();
        if ($courseIds === []) {
            return [];
        }

        return Course::query()
            ->whereIn('id', $courseIds)
            ->pluck('slug')
            ->filter()
            ->values()
            ->all();
    }

    private static function hasHistoricPaid(User $user): bool
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->real()
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout'])
            ->whereNotIn('status', Payment::PAID_STATUSES)
            ->whereIn('status', ['canceled', 'refunded', 'expired'])
            ->exists();
    }
}
