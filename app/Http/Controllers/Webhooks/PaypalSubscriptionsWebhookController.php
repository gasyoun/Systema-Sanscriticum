<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\BillingCommitmentStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingCommitment;
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
    private function materialisePaidCharge(array $resource, string $eventId, ?float $reportedAmount): Payment
    {
        $providerSubId = '';
        foreach (['billing_agreement_id', 'subscription_id'] as $key) {
            if (! empty($resource[$key]) && is_string($resource[$key])) {
                $providerSubId = $resource[$key];
                break;
            }
        }

        if ($providerSubId === '') {
            $this->rejectCharge('missing_subscription_id', $eventId);
        }

        $sub = BillingSubscription::query()
            ->where('provider', 'paypal')
            ->where('provider_subscription_id', $providerSubId)
            ->lockForUpdate()
            ->first();

        if ($sub === null) {
            $this->rejectCharge('unmatched_subscription', $eventId, [
                'provider_subscription_id' => $providerSubId,
            ]);
        }

        $saleId = (string) ($resource['id'] ?? $eventId);

        $existing = Payment::query()
            ->where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)
            ->where('transaction_id', $saleId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $commitment = BillingCommitment::query()
                ->where('billing_subscription_id', $sub->id)
                ->where('payment_id', $existing->id)
                ->lockForUpdate()
                ->first();

            if ($commitment === null) {
                $this->rejectCharge('existing_payment_without_commitment', $eventId, [
                    'payment_id' => $existing->id,
                    'sale_id' => $saleId,
                ]);
            }

            return $existing;
        }

        $commitments = BillingCommitment::query()
            ->where('billing_subscription_id', $sub->id)
            ->where('status', BillingCommitmentStatus::Scheduled)
            ->where(function ($query): void {
                $query->whereNull('due_on')->orWhereDate('due_on', '<=', today());
            })
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();

        if ($commitments->count() !== 1) {
            $this->rejectCharge(
                $commitments->isEmpty() ? 'unmatched_commitment' : 'ambiguous_commitment',
                $eventId,
                [
                    'billing_subscription_id' => $sub->id,
                    'candidate_commitment_ids' => $commitments->pluck('id')->all(),
                ],
            );
        }

        /** @var BillingCommitment $commitment */
        $commitment = $commitments->sole();
        $accessKey = $commitment->access_key ?: $commitment->tariff?->accessKey();

        if ($commitment->user_id !== $sub->user_id
            || ($commitment->course_id !== null && empty($accessKey))) {
            $this->rejectCharge('invalid_commitment', $eventId, [
                'billing_subscription_id' => $sub->id,
                'commitment_id' => $commitment->id,
            ]);
        }

        $foreignCurrency = $this->extractCurrency($resource);
        if ($reportedAmount === null || $foreignCurrency === null) {
            $this->rejectCharge('incomplete_provider_amount', $eventId, [
                'billing_subscription_id' => $sub->id,
                'commitment_id' => $commitment->id,
            ]);
        }

        $payment = Payment::create([
            'user_id' => $commitment->user_id,
            'course_id' => $commitment->course_id,
            'amount' => $commitment->amount_rub,
            'foreign_amount' => $reportedAmount,
            'foreign_currency' => $foreignCurrency,
            'tariff' => $accessKey,
            'start_block' => $commitment->start_block,
            'end_block' => $commitment->end_block,
            'status' => 'paid',
            'provider' => Payment::PROVIDER_PAYPAL_SUBSCRIPTION,
            'transaction_id' => $saleId,
        ]);

        $commitment->update([
            'status' => BillingCommitmentStatus::Charged,
            'payment_id' => $payment->id,
        ]);

        if ($sub->status !== BillingSubscriptionStatus::Active) {
            $sub->update(['status' => BillingSubscriptionStatus::Active]);
        }

        Log::info('paypal_subscriptions.webhook.payment_paid', [
            'payment_id' => $payment->id,
            'subscription_id' => $sub->id,
            'commitment_id' => $commitment->id,
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

    private function extractCurrency(array $resource): ?string
    {
        $candidates = [
            data_get($resource, 'amount.currency'),
            data_get($resource, 'amount.currency_code'),
            data_get($resource, 'amount.gross_amount.currency_code'),
            data_get($resource, 'billing_info.last_payment.amount.currency_code'),
        ];

        foreach ($candidates as $raw) {
            if (is_string($raw) && trim($raw) !== '') {
                return strtoupper(trim($raw));
            }
        }

        return null;
    }

    /**
     * Fail closed so PayPal retries after commitment configuration is repaired.
     *
     * @param  array<string, mixed>  $context
     * @return never
     */
    private function rejectCharge(string $reason, string $eventId, array $context = []): never
    {
        Log::alert('paypal_subscriptions.webhook.charge_rejected', [
            'reason' => $reason,
            'event_id' => $eventId,
            ...$context,
        ]);

        throw new \RuntimeException("PayPal charge rejected: {$reason}");
    }
}
