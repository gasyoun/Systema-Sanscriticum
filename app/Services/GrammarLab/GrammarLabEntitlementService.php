<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Enums\BillingSubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\Course;
use App\Models\GrammarLabEntitlement;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * H2495 / G4 — provider-independent Grammar Lab grant/revoke/expiry.
 *
 * Payment and subscription rows may emit lifecycle events. Product
 * authorization still asks only GrammarLabAccess::canUse(). This service
 * writes the normalized grant rows; it never inspects a gateway payload.
 */
final class GrammarLabEntitlementService
{
    public const KIND_COURSE = 'course';

    public const KIND_SUBSCRIPTION = 'subscription';

    public const KIND_PILOT = 'pilot';

    public const KIND_ADMIN = 'admin';

    /**
     * @return list<string>
     */
    public function entitlementCourseSlugs(): array
    {
        $slugs = config('grammar_lab.entitlement_course_slugs', []);

        return is_array($slugs) ? array_values(array_filter(array_map('strval', $slugs))) : [];
    }

    public function standaloneCourseSlug(): string
    {
        return trim((string) config('grammar_lab.subscription_course_slug', ''));
    }

    /**
     * @return list<string>
     */
    public function productCourseSlugs(): array
    {
        $slugs = $this->entitlementCourseSlugs();
        $standalone = $this->standaloneCourseSlug();
        if ($standalone !== '' && ! in_array($standalone, $slugs, true)) {
            $slugs[] = $standalone;
        }

        return $slugs;
    }

    public function isGrammarLabCourse(?Course $course): bool
    {
        if (! $course instanceof Course) {
            return false;
        }
        $slug = (string) $course->slug;

        return $slug !== '' && in_array($slug, $this->productCourseSlugs(), true);
    }

    public function isStandaloneCourse(?Course $course): bool
    {
        $standalone = $this->standaloneCourseSlug();

        return $course instanceof Course && $standalone !== '' && (string) $course->slug === $standalone;
    }

    public function grant(
        User $user,
        string $kind,
        ?string $sourceRef = null,
        ?Carbon $startsAt = null,
        ?Carbon $endsAt = null,
    ): GrammarLabEntitlement {
        $query = GrammarLabEntitlement::query()
            ->where('user_id', $user->id)
            ->where('grant_kind', $kind);
        if ($sourceRef !== null && $sourceRef !== '') {
            $query->where('source_ref', $sourceRef);
        } else {
            $query->whereNull('source_ref')->whereNull('revoked_at');
        }

        $row = $query->first();
        $payload = [
            'user_id' => $user->id,
            'grant_kind' => $kind,
            'source_ref' => $sourceRef,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'revoked_at' => null,
        ];

        if ($row instanceof GrammarLabEntitlement) {
            $row->fill($payload);
            $row->save();

            return $row->fresh();
        }

        return GrammarLabEntitlement::query()->create($payload);
    }

    public function revoke(GrammarLabEntitlement $grant): GrammarLabEntitlement
    {
        if ($grant->revoked_at === null) {
            $grant->revoked_at = now();
            $grant->save();
        }

        return $grant->fresh();
    }

    public function revokeBySource(string $sourceRef): int
    {
        if ($sourceRef === '') {
            return 0;
        }

        return GrammarLabEntitlement::query()
            ->where('source_ref', $sourceRef)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function syncFromPayment(Payment $payment): ?GrammarLabEntitlement
    {
        if (! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return null;
        }
        if ($payment->is_conditional || $payment->isDeposit() || $payment->isTrial()
            || $payment->isExpense() || $payment->isSalaryPayout()) {
            return null;
        }
        if (! $payment->user_id || ! $payment->course_id) {
            return null;
        }

        $course = $payment->course;
        if (! $this->isGrammarLabCourse($course)) {
            return null;
        }

        $kind = $this->isStandaloneCourse($course) ? self::KIND_SUBSCRIPTION : self::KIND_COURSE;
        $grant = $this->grant(
            $payment->user,
            $kind,
            'payment:'.$payment->id,
            now(),
            null,
        );

        Log::info('grammar_lab.entitlement.sync_payment', [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'grant_id' => $grant->id,
            'kind' => $kind,
        ]);

        return $grant;
    }

    public function syncFromSubscription(BillingSubscription $sub): ?GrammarLabEntitlement
    {
        if (! $sub->user_id) {
            return null;
        }
        $course = $sub->course;
        if (! $this->isGrammarLabCourse($course) && ! $this->isStandaloneCourse($course)) {
            return null;
        }

        $source = 'billing_subscription:'.$sub->id;
        $user = $sub->user;
        if (! $user instanceof User) {
            return null;
        }

        $kind = $this->isStandaloneCourse($course) ? self::KIND_SUBSCRIPTION : self::KIND_COURSE;

        if ($sub->status === BillingSubscriptionStatus::Active) {
            $grant = $this->grant($user, $kind, $source, now(), null);
            Log::info('grammar_lab.entitlement.sync_subscription', [
                'subscription_id' => $sub->id,
                'user_id' => $sub->user_id,
                'grant_id' => $grant->id,
                'status' => $sub->status->value,
            ]);

            return $grant;
        }

        if (in_array($sub->status, [BillingSubscriptionStatus::Cancelled, BillingSubscriptionStatus::Completed], true)) {
            $n = $this->revokeBySource($source);
            Log::info('grammar_lab.entitlement.revoke_subscription', [
                'subscription_id' => $sub->id,
                'user_id' => $sub->user_id,
                'revoked' => $n,
                'status' => $sub->status->value,
            ]);

            return GrammarLabEntitlement::query()->where('source_ref', $source)->first();
        }

        return GrammarLabEntitlement::query()->where('source_ref', $source)->first();
    }
}
