<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\BillingSubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingSubscription;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\PaypalSubscriptionsService;
use App\Services\Payments\PaypalWebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PayPal Subscriptions webhooks (H2027 Phase 1).
 *
 * Flag OFF → 404. Flag ON but not configured → 503.
 * Flag ON + configured → signature verify → ledger (H1359) → map events
 * to billing_subscriptions + Payment (provider=paypal_subscription).
 *
 * Manual claim path is unaffected (PaypalClaimController).
 */
final class PaypalSubscriptionsWebhookController extends Controller
{
    public function __construct(
        private readonly PaypalSubscriptionsService $subscriptions,
        private readonly PaypalWebhookSignatureVerifier $signatureVerifier,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->subscriptions->isEnabled()) {
            return response('Not Found', 404);
        }

        if (! $this->subscriptions->isConfigured()) {
            Log::warning('paypal_subscriptions.webhook.unconfigured', [
                'path' => $request->path(),
            ]);

            return response('PayPal Subscriptions not configured', 503);
        }

        $raw = $request->getContent();
        if (! $this->signatureVerifier->verify($request)) {
            Log::warning('paypal_subscriptions.webhook.invalid_signature', [
                'ip' => $request->ip(),
            ]);

            return response('Invalid signature', 401);
        }

        $eventType = (string) $request->input('event_type', '');
        $eventId = (string) $request->input('id', '');
        $eventHash = hash('sha256', $eventId !== '' ? $eventId : $raw);

        if (PaymentWebhookEvent::where('event_hash', $eventHash)->exists()) {
            Log::info('paypal_subscriptions.webhook.duplicate', [
                'event_type' => $eventType,
                'event_id' => $eventId,
            ]);

            return response('OK', 200);
        }

        try {
            DB::transaction(function () use ($request, $eventType, $eventId, $eventHash): void {
                $resource = $request->input('resource', []);
                if (! is_array($resource)) {
                    $resource = [];
                }

                $payment = null;
                $decision = PaymentWebhookEvent::DECISION_APPLIED;
                $reportedAmount = $this->extractAmount($resource);

                if ($eventType === 'BILLING.SUBSCRIPTION.ACTIVATED'
                    || $eventType === 'BILLING.SUBSCRIPTION.UPDATED'
                    || $eventType === 'BILLING.SUBSCRIPTION.RE-ACTIVATED') {
                    $this->applySubscriptionStatus($resource, BillingSubscriptionStatus::Active, $eventId);
                } elseif ($eventType === 'BILLING.SUBSCRIPTION.SUSPENDED') {
                    $this->applySubscriptionStatus($resource, BillingSubscriptionStatus::PastDue, $eventId);
                } elseif ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED'
                    || $eventType === 'BILLING.SUBSCRIPTION.EXPIRED') {
                    $status = $eventType === 'BILLING.SUBSCRIPTION.EXPIRED'
                        ? BillingSubscriptionStatus::Completed
                        : BillingSubscriptionStatus::Cancelled;
                    $this->applySubscriptionStatus($resource, $status, $eventId);
                } elseif ($eventType === 'PAYMENT.SALE.COMPLETED'
                    || $eventType === 'BILLING.SUBSCRIPTION.PAYMENT.COMPLETED') {
                    $payment = $this->materialisePaidCharge($resource, $eventId, $reportedAmount);
                    if ($payment === null) {
                        $decision = PaymentWebhookEvent::DECISION_UNMATCHED;
                    }
                } else {
                    $decision = PaymentWebhookEvent::DECISION_UNMATCHED;
                    Log::info('paypal_subscriptions.webhook.unhandled_event', [
                        'event_type' => $eventType,
                        'event_id' => $eventId,
                    ]);
                }

                PaymentWebhookEvent::firstOrCreate(
                    ['event_hash' => $eventHash],
                    [
                        'provider' => 'paypal',
                        'payment_id' => $payment?->id,
                        'bank_status' => $eventType !== '' ? $eventType : 'unknown',
                        'reported_amount' => $reportedAmount,
                        'decision' => $decision,
                        'created_at' => now(),
                    ]
                );
            });
        } catch (\Throwable $e) {
            Log::error('paypal_subscriptions.webhook.error', [
                'message' => $e->getMessage(),
            ]);

            return response('Server error', 500);
        }

        return response('OK', 200);
    }

    private function applySubscriptionStatus(array $resource, BillingSubscriptionStatus $status, string $eventId): void
    {
        $providerSubId = (string) ($resource['id'] ?? '');
        if ($providerSubId === '') {
            return;
        }

        $row = BillingSubscription::query()
            ->where('provider', 'paypal')
            ->where('provider_subscription_id', $providerSubId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            Log::info('paypal_subscriptions.webhook.subscription_unmatched', [
                'provider_subscription_id' => $providerSubId,
                'event_id' => $eventId,
            ]);

            return;
        }

        $meta = is_array($row->meta) ? $row->meta : [];
        $meta['last_paypal_event_id'] = $eventId;
        $row->update([
            'status' => $status,
            'meta' => $meta,
        ]);
    }

    /**
     * Charge → Payment (provider=paypal_subscription). Reuses fireOnPaid via status=paid.
     */
    private function materialisePaidCharge(array $resource, string $eventId, ?float $reportedAmount): ?Payment
    {
        $providerSubId = '';
        foreach (['billing_agreement_id', 'subscription_id'] as $key) {
            if (! empty($resource[$key]) && is_string($resource[$key])) {
                $providerSubId = $resource[$key];
                break;
            }
        }

        if ($providerSubId === '') {
            return null;
        }

        $sub = BillingSubscription::query()
            ->where('provider', 'paypal')
            ->where('provider_subscription_id', $providerSubId)
            ->lockForUpdate()
            ->first();

        if ($sub === null) {
            Log::warning('paypal_subscriptions.webhook.charge_unmatched_subscription', [
                'provider_subscription_id' => $providerSubId,
                'event_id' => $eventId,
            ]);

            return null;
        }

        $amount = $reportedAmount ?? (float) $sub->amount_rub;
        $saleId = (string) ($resource['id'] ?? $eventId);

        $existing = Payment::query()
            ->where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)
            ->where('transaction_id', $saleId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->status !== 'paid') {
                $existing->update(['status' => 'paid']);
            }

            return $existing->fresh();
        }

        $payment = Payment::create([
            'user_id' => $sub->user_id,
            'course_id' => $sub->course_id,
            'amount' => $amount,
            'tariff' => 'subscription',
            'status' => 'paid',
            'provider' => Payment::PROVIDER_PAYPAL_SUBSCRIPTION,
            'transaction_id' => $saleId,
        ]);

        if ($sub->status !== BillingSubscriptionStatus::Active) {
            $sub->update(['status' => BillingSubscriptionStatus::Active]);
        }

        Log::info('paypal_subscriptions.webhook.payment_paid', [
            'payment_id' => $payment->id,
            'subscription_id' => $sub->id,
            'event_id' => $eventId,
        ]);

        return $payment;
    }

    private function extractAmount(array $resource): ?float
    {
        $candidates = [
            data_get($resource, 'amount.total'),
            data_get($resource, 'amount.value'),
            data_get($resource, 'amount.gross_amount.value'),
            data_get($resource, 'billing_info.last_payment.amount.value'),
        ];

        foreach ($candidates as $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }
            if (is_numeric($raw)) {
                return round((float) $raw, 2);
            }
        }

        return null;
    }
}
