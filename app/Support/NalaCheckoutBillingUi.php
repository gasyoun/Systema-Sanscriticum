<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;

/**
 * H2166 — visibility gate for the inert Mode-A "auto-pay monthly" checkout
 * radio on the Nalopākhyāna course page.
 *
 * Nala sells through the ordinary one-shot checkout (ShopController /
 * CheckoutController / PaymentController -> TochkaPaymentService), same as
 * every other Systema course (see ARCHITECTURE_NALOPAKHYANA_SUBHASHITA_
 * COURSES_2026.md §4). This class does not touch Payment/Tariff/checkout
 * money logic at all — it only decides whether the checkout view renders a
 * second, currently-disabled radio option next to the default "pay once"
 * one. The radio itself stays disabled in the DOM even when visible: H2026
 * Phase 1 (TochkaPaymentService::createSubscriptionWithReceipt) is a
 * separate, not-yet-built handoff gated on Systema-Sanscriticum#998.
 *
 * Visible only when ALL of:
 *   - features.tochka_recurring is ON (master recurring-billing switch)
 *   - features.nala_subscriptions_live is ON (Nala-specific, separate switch)
 *   - the tariff's course is THE Nala cohort course (config/cohort_courses.php)
 */
final class NalaCheckoutBillingUi
{
    public static function subscriptionRadioVisibleFor(?Course $course): bool
    {
        if (! $course) {
            return false;
        }

        if (! (bool) config('features.tochka_recurring', false)) {
            return false;
        }

        if (! (bool) config('features.nala_subscriptions_live', false)) {
            return false;
        }

        $nalaSlug = config('cohort_courses.nalopakhyana.course_slug');

        return is_string($nalaSlug) && $nalaSlug !== '' && $course->slug === $nalaSlug;
    }
}
