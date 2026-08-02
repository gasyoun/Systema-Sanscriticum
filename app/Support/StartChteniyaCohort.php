<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;

/**
 * H2105 — «Старт чтения» Akro-style 5-week paid cohort pilot.
 *
 * NOT a new money/access system. The cohort is an ordinary Course+Tariff sold
 * through the existing generic checkout (ShopController/CheckoutController/
 * PaymentController → TochkaPaymentService) and unlocked through the existing
 * PaymentObserver::grantAccess() course_group pivot — same as any other course
 * (D10: prefer Tariff+Course over a bespoke marathon_paid-shaped tariff).
 * Course/Tariff/Group rows (price, dates) are created by ops in Filament; see
 * docs/PLAN_AKRO_START_CHTENIYA_2026.md D6/D10.
 *
 * This class is the single source of truth for the `start_chteniya_cohort`
 * entitlement key (ARCHITECTURE_AKRO_START_CHTENIYA_2026.md "Cohort
 * entitlement") that sibling handoffs (H2106 cabinet wire, H2110 reader
 * import, H2111 tap-token UI) gate their multi-pack reader routes and cohort
 * SRS deck on. Gate: features.start_chteniya_cohort — this flag is scoped to
 * THIS cohort only and never touches config/srs.php (D11: no global
 * SRS_ENABLED=true flip).
 */
final class StartChteniyaCohort
{
    public static function course(): ?Course
    {
        $slug = config('start_chteniya.course_slug');
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Course::where('slug', $slug)->first();
    }

    /**
     * True only for a real, paid, access-granting payment on the cohort course —
     * never for a deposit/trial/conditional (promised, not yet paid) row. Mirrors
     * the access-granting filter Payment::accessGrantingPaymentsForUser() uses
     * elsewhere in the money core.
     */
    public static function hasEntitlement(?User $user): bool
    {
        if (! config('features.start_chteniya_cohort', false)) {
            return false;
        }

        if (! $user) {
            return false;
        }

        $course = self::course();
        if (! $course) {
            return false;
        }

        return Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->paid()
            ->real()
            ->whereNotIn('tariff', ['deposit', 'trial'])
            ->exists();
    }
}
