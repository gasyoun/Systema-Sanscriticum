<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * H2164 — slug-parameterized generalization of H2105's StartChteniyaCohort.
 *
 * NOT a new money/access system. Every course keyed in
 * config/cohort_courses.php is an ordinary Course+Tariff sold through the
 * existing generic checkout (ShopController/CheckoutController/
 * PaymentController → TochkaPaymentService) and unlocked through the
 * existing PaymentObserver::grantAccess() course_group pivot — same as any
 * other course. This class is a read-only consumer of Payment; it never
 * touches Payment/Tariff/checkout code itself.
 *
 * H2105's App\Support\StartChteniyaCohort + config/start_chteniya.php are
 * left untouched (plan §3 default: leave, don't wrap) — this is a second,
 * parameterized path for new courses (Nalopakhyana, Subhashita, ...) that
 * reuses the same entitlement logic almost verbatim, keyed by slug instead
 * of hardwired to one config value.
 */
final class CourseCohortEntitlement
{
    /**
     * @return array{course_slug: string, enabled: bool}|null
     */
    private static function config(string $slug): ?array
    {
        $entry = config("cohort_courses.{$slug}");
        if (! is_array($entry) || ! isset($entry['course_slug'])) {
            return null;
        }

        $courseSlug = $entry['course_slug'];
        if (! is_string($courseSlug) || $courseSlug === '') {
            return null;
        }

        return [
            'course_slug' => $courseSlug,
            'enabled' => (bool) ($entry['enabled'] ?? false),
        ];
    }

    public static function course(string $slug): ?Course
    {
        $entry = self::config($slug);
        if (! $entry) {
            return null;
        }

        return Course::where('slug', $entry['course_slug'])->first();
    }

    /**
     * True only for a real, paid, access-granting payment on THIS slug's
     * course — never for a deposit/trial/conditional (promised, not yet
     * paid) row, and never for a payment on a different cohort course
     * (cross-course entitlement leak is the fail condition H2105 never
     * needed to guard against with only one cohort).
     */
    public static function hasEntitlement(?User $user, string $slug): bool
    {
        $entry = self::config($slug);
        if (! $entry || ! $entry['enabled']) {
            return false;
        }

        if (! $user) {
            return false;
        }

        $course = self::course($slug);
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

    /**
     * Every user currently entitled to THIS slug's course — the batch form
     * of hasEntitlement(), mirroring StartChteniyaCohort::entitledUsers().
     * Same filter, same flag gate, so a user can never appear here while
     * hasEntitlement() would return false for them.
     *
     * @return Collection<int,User>
     */
    public static function entitledUsers(string $slug): Collection
    {
        $entry = self::config($slug);
        if (! $entry || ! $entry['enabled']) {
            return collect();
        }

        $course = self::course($slug);
        if (! $course) {
            return collect();
        }

        return User::query()
            ->whereHas('payments', function ($q) use ($course) {
                $q->where('course_id', $course->id)
                    ->paid()
                    ->real()
                    ->whereNotIn('tariff', ['deposit', 'trial']);
            })
            ->get();
    }
}
