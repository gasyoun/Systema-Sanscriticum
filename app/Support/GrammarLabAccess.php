<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\GrammarLabEntitlement;
use App\Models\Payment;
use App\Models\User;

/**
 * H2493 — single Grammar Lab entitlement question: canUse().
 *
 * Provider-independent. Payment rows are read only as already-normalized
 * access-granting events; UI/controllers never inspect a gateway. G4 may
 * add subscription lifecycle; this resolver already accepts explicit grant
 * rows (admin/pilot/course/subscription) so G4 does not need a second gate.
 */
final class GrammarLabAccess
{
    public const FEATURE = 'grammar_lab';

    public static function featureOn(): bool
    {
        return (bool) config('features.grammar_lab', false);
    }

    public static function semanticOn(): bool
    {
        return self::featureOn() && (bool) config('features.grammar_lab_semantic', false);
    }

    public static function canUse(?User $user): bool
    {
        if (! self::featureOn() || $user === null) {
            return false;
        }

        if ($user->isAdminLike()) {
            return true;
        }

        if (self::hasActiveGrant($user)) {
            return true;
        }

        return self::hasPaidConfiguredCourse($user);
    }

    public static function hasActiveGrant(User $user): bool
    {
        return GrammarLabEntitlement::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->exists();
    }

    public static function hasPaidConfiguredCourse(User $user): bool
    {
        $slugs = config('grammar_lab.entitlement_course_slugs', []);
        if (! is_array($slugs) || $slugs === []) {
            return false;
        }

        $ids = Course::query()->whereIn('slug', $slugs)->pluck('id');
        if ($ids->isEmpty()) {
            return false;
        }

        return Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $ids)
            ->paid()
            ->real()
            ->whereNotIn('tariff', ['deposit', 'trial', 'Расход', 'salary_payout'])
            ->exists();
    }
}
