<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\MembershipFunnelEvent;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class MembershipFunnelAnalytics
{
    public function record(
        string $event,
        string $sourceSite,
        ?User $user = null,
        ?Tariff $tariff = null,
        ?Payment $payment = null,
        ?string $archiveKey = null,
        ?string $feature = null,
        ?Request $request = null,
        array $metadata = [],
        ?string $tier = null,
        ?int $termMonths = null,
        ?int $courseId = null,
    ): void {
        if (! (bool) config('features.membership_funnel_analytics', false)) {
            return;
        }

        $sourceSite = in_array($sourceSite, ['samskrte', 'samskrtam', 'private_archive', 'system'], true)
            ? $sourceSite
            : 'unknown';

        try {
            MembershipFunnelEvent::create([
                'event_name' => $event,
                'user_id' => $user?->id,
                'course_id' => $tariff?->course_id ?? $payment?->course_id ?? $courseId,
                'tariff_id' => $tariff?->id,
                'payment_id' => $payment?->id,
                'visitor_hash' => $request ? hash('sha256', $request->session()->getId()) : null,
                'tier' => $tariff?->membership_tier?->value ?? $tier,
                'term_months' => $tariff?->membership_months ?? $termMonths,
                'source_site' => $sourceSite,
                'archive_key' => $archiveKey,
                'feature' => $feature,
                'metadata' => $metadata === [] ? null : $metadata,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Membership funnel event failed', [
                'event' => $event,
                'payment_id' => $payment?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function recordPayment(Payment $payment): void
    {
        if (! $payment->user || ! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return;
        }

        $tariff = $this->tariffForPayment($payment);
        if (! $tariff) {
            return;
        }

        $prior = MembershipFunnelEvent::query()
            ->where('user_id', $payment->user_id)
            ->where('event_name', MembershipFunnelEvent::CHECKOUT)
            ->where('tariff_id', $tariff->id)
            ->latest('id')
            ->first();

        $sessionSource = request()->hasSession()
            ? request()->session()->get("membership_checkout_source.{$tariff->id}")
            : null;
        $source = $prior?->source_site
            ?? (is_string($sessionSource) && $sessionSource !== '' ? $sessionSource : 'system');
        $event = $tariff->membership_tier instanceof MembershipTier
            ? $this->paymentLifecycleEvent($payment)
            : MembershipFunnelEvent::PAYMENT;
        $this->record($event, $source, $payment->user, $tariff, $payment);
    }

    public function recordLapse(User $user, string $tier, int $termMonths, int $membershipId): void
    {
        $this->record(
            MembershipFunnelEvent::LAPSE,
            'system',
            $user,
            metadata: ['membership_id' => $membershipId, 'tier' => $tier, 'term_months' => $termMonths],
            tier: $tier,
            termMonths: $termMonths,
        );
    }

    private function paymentLifecycleEvent(Payment $payment): string
    {
        $memberships = ClubMembership::query()->where('user_id', $payment->user_id);
        $hadLapse = (clone $memberships)->whereNotNull('revoked_at')->where('revoke_reason', 'lapsed')->exists();
        $otherPaidPeriod = (clone $memberships)->where('payment_id', '!=', $payment->id)->exists();
        $otherActivePeriod = (clone $memberships)->where('payment_id', '!=', $payment->id)->active()->exists();

        if ($hadLapse && ! $otherActivePeriod) {
            return MembershipFunnelEvent::RESTORATION;
        }

        return $otherPaidPeriod ? MembershipFunnelEvent::RENEWAL : MembershipFunnelEvent::PAYMENT;
    }

    private function tariffForPayment(Payment $payment): ?Tariff
    {
        if (! $payment->course_id || ! $payment->tariff) {
            return null;
        }

        return Tariff::query()
            ->where('course_id', $payment->course_id)
            ->get()
            ->first(fn (Tariff $tariff): bool => $tariff->accessKey() === $payment->tariff);
    }
}
