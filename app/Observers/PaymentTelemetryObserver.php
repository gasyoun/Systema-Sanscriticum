<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityEvent;
use App\Models\Payment;
use App\Services\Activity\CabinetTelemetry;
use App\Services\Activity\FunnelTelemetry;
use App\Services\Membership\MembershipFunnelAnalytics;

/**
 * Телеметрия оплат для baseline ремейка кабинета (H962, Phase 0):
 * access.renewal.complete по спеке §4.
 *
 * Отдельный обсервер по прецеденту PaymentAuditObserver — НЕ смешиваем с
 * выдачей доступа (PaymentObserver) и не трогаем денежную логику: только
 * читаем состоявшийся переход status→paid и пишем событие.
 *
 * Классификатор «это продление» — is_self_service: флаг ставят self-service
 * сценарии погашения долга/продления из кабинета (DebtPaymentResolver и
 * чекаут по долговым тариф-ссылкам). Платежи, созданные менеджером руками,
 * в baseline продлений не входят — фиксируем поведение СТУДЕНТА в кабинете.
 */
class PaymentTelemetryObserver
{
    public function __construct(
        private readonly CabinetTelemetry $telemetry,
        private readonly FunnelTelemetry $funnel,
        private readonly MembershipFunnelAnalytics $membershipFunnel,
    ) {}

    public function updated(Payment $payment): void
    {
        if ($payment->wasChanged('status')) {
            $this->emitIfSelfServicePaid($payment);
            $this->emitFunnelPaymentSuccess($payment);
        }
    }

    /** Self-service платёж может родиться сразу paid (без перехода) — ловим и это. */
    public function created(Payment $payment): void
    {
        $this->emitIfSelfServicePaid($payment);
        $this->emitFunnelPaymentSuccess($payment);
    }

    private function emitIfSelfServicePaid(Payment $payment): void
    {
        // PAID_STATUSES, не литерал 'paid': разные пути сохранения пишут
        // 'paid' либо 'success' (см. Payment::scopePaid).
        if (! in_array($payment->status, Payment::PAID_STATUSES, true)
            || ! $payment->is_self_service
            || ! $payment->user) {
            return;
        }

        $this->telemetry->emit(
            user: $payment->user,
            event: ActivityEvent::ACCESS_RENEWAL_COMPLETE,
            data: [
                'payment_id' => $payment->id,
                'course_id' => $payment->course_id,
                'amount' => (string) $payment->amount,
            ],
        );
    }

    /** H2378: qualifying paid course-intent → payment_success (excludes deposit/trial/etc.). */
    private function emitFunnelPaymentSuccess(Payment $payment): void
    {
        if (! $payment->user || ! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return;
        }

        $this->funnel->emitPaymentSuccess($payment->user, $payment);
        $this->membershipFunnel->recordPayment($payment);
    }
}
