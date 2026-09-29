<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\BillingCommitmentStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Exceptions\PaypalChargeRejectedException;
use App\Http\Controllers\Controller;
use App\Models\BillingCommitment;
use App\Models\BillingSubscription;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\GrammarLab\GrammarLabEntitlementService;
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

        $resource = $request->input('resource', []);
        if (! is_array($resource)) {
            $resource = [];
        }
        $reportedAmount = $this->extractAmount($resource);
        $wave1 = (bool) config('features.payment_fix_wave1');
        $eventTime = $this->eventTime($resource, (string) $request->input('create_time', ''));

        try {
            DB::transaction(function () use ($resource, $reportedAmount, $eventType, $eventId, $eventHash, $wave1, $eventTime): void {
                $payment = null;
                $decision = PaymentWebhookEvent::DECISION_APPLIED;

                if ($wave1 && $eventType === 'BILLING.SUBSCRIPTION.UPDATED') {
                    // H5007 (audit H4): UPDATED больше не значит «Active» безусловно —
                    // статус берётся из resource.status; без статуса ничего не меняем.
                    $status = $this->statusFromResource($resource);
                    $decision = $status === null
                        ? PaymentWebhookEvent::DECISION_UNMATCHED
                        : $this->applySubscriptionStatus($resource, $status, $eventId, $eventTime);
                } elseif ($eventType === 'BILLING.SUBSCRIPTION.ACTIVATED'
                    || $eventType === 'BILLING.SUBSCRIPTION.UPDATED'
                    || $eventType === 'BILLING.SUBSCRIPTION.RE-ACTIVATED') {
                    $decision = $this->applySubscriptionStatus($resource, BillingSubscriptionStatus::Active, $eventId, $eventTime);
                } elseif ($eventType === 'BILLING.SUBSCRIPTION.SUSPENDED') {
                    $decision = $this->applySubscriptionStatus($resource, BillingSubscriptionStatus::PastDue, $eventId, $eventTime);
                } elseif ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED'
                    || $eventType === 'BILLING.SUBSCRIPTION.EXPIRED') {
                    $status = $eventType === 'BILLING.SUBSCRIPTION.EXPIRED'
                        ? BillingSubscriptionStatus::Completed
                        : BillingSubscriptionStatus::Cancelled;
                    $decision = $this->applySubscriptionStatus($resource, $status, $eventId, $eventTime);
                } elseif ($eventType === 'PAYMENT.SALE.COMPLETED'
                    || $eventType === 'BILLING.SUBSCRIPTION.PAYMENT.COMPLETED') {
                    $payment = $this->materialisePaidCharge($resource, $eventId, $reportedAmount);
                } elseif ($wave1 && ($eventType === 'PAYMENT.SALE.REFUNDED'
                    || $eventType === 'PAYMENT.SALE.REVERSED'
                    || $eventType === 'PAYMENT.SALE.DENIED')) {
                    // H5007 (audit H3): возврат/чарджбэк/отказ раньше падали в else,
                    // журналились unmatched и отвечали 200 — платёж оставался paid,
                    // доступ не отзывался.
                    [$payment, $decision] = $this->reverseCharge($resource, $eventType, $eventId);
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
        } catch (PaypalChargeRejectedException $e) {
            // H2304 spec 3: строка-отказ пишется ПОСЛЕ отката транзакции — иначе
            // журнал откатывался вместе с ней. Хэш пер-попыточный (одна строка на
            // каждую доставку, как у Точки с per-redelivery JWT); канонический
            // hash(eventId) на отказе не занимаем, чтобы ретрай PayPal после
            // починки конфигурации прошёл заново, а не упёрся в replay-guard.
            PaymentWebhookEvent::create([
                'provider' => 'paypal',
                'payment_id' => null,
                'event_hash' => hash('sha256', $eventId.'|'.$e->reason.'|'.uniqid('', true)),
                'bank_status' => $eventType !== '' ? $eventType : 'unknown',
                'reported_amount' => $reportedAmount,
                'decision' => $e->decision,
                'created_at' => now(),
            ]);

            return response('Charge rejected: '.$e->reason, 422);
        } catch (\Throwable $e) {
            Log::error('paypal_subscriptions.webhook.error', [
                'message' => $e->getMessage(),
            ]);

            return response('Server error', 500);
        }

        return response('OK', 200);
    }

    /** @return string PaymentWebhookEvent::DECISION_* */
    private function applySubscriptionStatus(array $resource, BillingSubscriptionStatus $status, string $eventId, ?string $eventTime = null): string
    {
        $providerSubId = (string) ($resource['id'] ?? '');
        if ($providerSubId === '') {
            return PaymentWebhookEvent::DECISION_APPLIED;
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

            return PaymentWebhookEvent::DECISION_APPLIED;
        }

        $meta = is_array($row->meta) ? $row->meta : [];

        if (config('features.payment_fix_wave1')) {
            // H5007 (audit H4): out-of-order guard. (a) Отменённая/завершённая
            // подписка не воскресает от позднего ACTIVATED/UPDATED/RE-ACTIVATED;
            // (b) событие старше уже применённого (по resource.update_time /
            // status_update_time / create_time) не применяется. Паритет с
            // Точкой: DECISION_REJECTED_RESURRECTION.
            $terminal = in_array($row->status, [BillingSubscriptionStatus::Cancelled, BillingSubscriptionStatus::Completed], true);
            $lastTime = isset($meta['last_paypal_event_time']) && is_string($meta['last_paypal_event_time'])
                ? $meta['last_paypal_event_time']
                : null;
            $stale = $eventTime !== null && $lastTime !== null && strtotime($eventTime) < strtotime($lastTime);

            if (($terminal && $status === BillingSubscriptionStatus::Active) || $stale) {
                Log::warning('paypal_subscriptions.webhook.rejected_resurrection', [
                    'provider_subscription_id' => $providerSubId,
                    'event_id' => $eventId,
                    'current_status' => $row->status->value,
                    'incoming_status' => $status->value,
                    'event_time' => $eventTime,
                    'last_event_time' => $lastTime,
                ]);

                return PaymentWebhookEvent::DECISION_REJECTED_RESURRECTION;
            }

            if ($eventTime !== null) {
                $meta['last_paypal_event_time'] = $eventTime;
            }
        }

        $meta['last_paypal_event_id'] = $eventId;
        $row->update([
            'status' => $status,
            'meta' => $meta,
        ]);

        app(GrammarLabEntitlementService::class)->syncFromSubscription($row->fresh());

        return PaymentWebhookEvent::DECISION_APPLIED;
    }

    /** H5007 (audit H4): статус подписки из resource.status (UPDATED-события). */
    private function statusFromResource(array $resource): ?BillingSubscriptionStatus
    {
        $raw = strtoupper(trim((string) ($resource['status'] ?? '')));

        return match ($raw) {
            'ACTIVE' => BillingSubscriptionStatus::Active,
            'SUSPENDED' => BillingSubscriptionStatus::PastDue,
            'CANCELLED' => BillingSubscriptionStatus::Cancelled,
            'EXPIRED' => BillingSubscriptionStatus::Completed,
            default => null,
        };
    }

    /** H5007 (audit H4): момент события — resource.update_time / status_update_time / create_time вебхука. */
    private function eventTime(array $resource, string $createTime): ?string
    {
        foreach ([$resource['update_time'] ?? null, $resource['status_update_time'] ?? null, $createTime] as $raw) {
            if (is_string($raw) && trim($raw) !== '' && strtotime($raw) !== false) {
                return trim($raw);
            }
        }

        return null;
    }

    /**
     * H5007 (audit H3): PAYMENT.SALE.REFUNDED / REVERSED / DENIED → платёж
     * подписки в canceled (Payment::booted() штатно откатывает прану, реферал,
     * доступ — reconcileAccessAfterReversal), commitment в cancelled (refund /
     * reversed) или failed (denied), при отказе подписка → past_due. Нет такого
     * платежа → unmatched (200, журнал), как у любого чужого события.
     *
     * @return array{0: ?Payment, 1: string}
     */
    private function reverseCharge(array $resource, string $eventType, string $eventId): array
    {
        $saleId = '';
        foreach (['sale_id', 'id'] as $key) {
            if (! empty($resource[$key]) && is_string($resource[$key])) {
                $saleId = $resource[$key];
                break;
            }
        }

        $payment = $saleId === '' ? null : Payment::query()
            ->where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)
            ->where('transaction_id', $saleId)
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            Log::warning('paypal_subscriptions.webhook.reversal_unmatched', [
                'event_type' => $eventType,
                'event_id' => $eventId,
                'sale_id' => $saleId,
            ]);

            return [null, PaymentWebhookEvent::DECISION_UNMATCHED];
        }

        if ($payment->status !== 'canceled') {
            $payment->update(['status' => 'canceled']);
        }

        $commitment = BillingCommitment::query()
            ->where('payment_id', $payment->id)
            ->lockForUpdate()
            ->first();
        $commitment?->update([
            'status' => $eventType === 'PAYMENT.SALE.DENIED'
                ? BillingCommitmentStatus::Failed
                : BillingCommitmentStatus::Cancelled,
        ]);

        $sub = $commitment?->billing_subscription_id
            ? BillingSubscription::query()->lockForUpdate()->find($commitment->billing_subscription_id)
            : null;
        if ($sub !== null) {
            $meta = is_array($sub->meta) ? $sub->meta : [];
            $meta['last_paypal_event_id'] = $eventId;
            $meta['last_reversal_event_type'] = $eventType;
            $sub->update([
                'status' => $eventType === 'PAYMENT.SALE.DENIED' && $sub->status === BillingSubscriptionStatus::Active
                    ? BillingSubscriptionStatus::PastDue
                    : $sub->status,
                'meta' => $meta,
            ]);
            app(GrammarLabEntitlementService::class)->syncFromSubscription($sub->fresh());
        }

        Log::info('paypal_subscriptions.webhook.payment_reversed', [
            'event_type' => $eventType,
            'event_id' => $eventId,
            'payment_id' => $payment->id,
            'commitment_id' => $commitment?->id,
            'subscription_id' => $sub?->id,
        ]);

        return [$payment, PaymentWebhookEvent::DECISION_APPLIED];
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

        // H2304 spec 3: паритет с Точкой — сумма из вебхука сверяется с
        // ожиданием commitment'а. Ожидание объявляется в валюте провайдера
        // (meta.expected_charge_amount/_currency на commitment или подписке) —
        // commitment.amount_rub рублёвый и с валютной суммой несравним.
        // Ожидание не объявлено => fail closed: маршрут ещё не жил в проде
        // (создание подписок — Phase 1 placeholder), контракт фиксируем до
        // первого живого списания. $1 против ₽40 000 не проходит ни одной веткой.
        [$expectedAmount, $expectedCurrency] = $this->expectedCharge($commitment, $sub);
        if ($expectedAmount === null || $expectedCurrency === null) {
            $this->rejectCharge('missing_expected_charge', $eventId, [
                'billing_subscription_id' => $sub->id,
                'commitment_id' => $commitment->id,
            ], PaymentWebhookEvent::DECISION_REJECTED_AMOUNT_MISMATCH);
        }

        $tolerance = (float) config('checkout.paypal_webhook_amount_tolerance', 1.00);
        if ($foreignCurrency !== $expectedCurrency
            || abs($reportedAmount - $expectedAmount) > $tolerance
        ) {
            $this->rejectCharge('amount_mismatch', $eventId, [
                'billing_subscription_id' => $sub->id,
                'commitment_id' => $commitment->id,
                'reported' => $reportedAmount.' '.$foreignCurrency,
                'expected' => $expectedAmount.' '.$expectedCurrency,
                'tolerance' => $tolerance,
            ], PaymentWebhookEvent::DECISION_REJECTED_AMOUNT_MISMATCH);
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

        // H5007 (audit H4): поздний SALE.COMPLETED не воскрешает отменённую /
        // завершённую подписку — Active только из pending_first_pay / past_due.
        $reactivatable = config('features.payment_fix_wave1')
            ? in_array($sub->status, [BillingSubscriptionStatus::PendingFirstPay, BillingSubscriptionStatus::PastDue], true)
            : $sub->status !== BillingSubscriptionStatus::Active;
        if ($reactivatable) {
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
     * Ожидаемое списание в валюте провайдера: meta.expected_charge_amount +
     * meta.expected_charge_currency на commitment'е, fallback — на подписке.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function expectedCharge(BillingCommitment $commitment, BillingSubscription $sub): array
    {
        foreach ([$commitment->meta, $sub->meta] as $meta) {
            if (is_array($meta)
                && isset($meta['expected_charge_amount'], $meta['expected_charge_currency'])
                && is_numeric($meta['expected_charge_amount'])
                && is_string($meta['expected_charge_currency'])
                && trim($meta['expected_charge_currency']) !== ''
            ) {
                return [
                    round((float) $meta['expected_charge_amount'], 2),
                    strtoupper(trim($meta['expected_charge_currency'])),
                ];
            }
        }

        return [null, null];
    }

    /**
     * Fail closed so PayPal retries after commitment configuration is repaired.
     * The controller catches the exception OUTSIDE the transaction and journals
     * the rejected delivery (H2304 spec 3).
     *
     * @param  array<string, mixed>  $context
     */
    private function rejectCharge(
        string $reason,
        string $eventId,
        array $context = [],
        string $decision = PaymentWebhookEvent::DECISION_REJECTED_CHARGE,
    ): never {
        Log::alert('paypal_subscriptions.webhook.charge_rejected', [
            'reason' => $reason,
            'event_id' => $eventId,
            ...$context,
        ]);

        throw new PaypalChargeRejectedException($reason, $decision, $context);
    }
}
