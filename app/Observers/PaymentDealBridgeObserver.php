<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealTransition;
use App\Models\Payment;

/**
 * Мост «состоявшаяся оплата → сделка» (GC-C1 / H1641).
 *
 * ОТДЕЛЬНЫЙ обсервер по прецеденту PaymentAuditObserver / PaymentTelemetryObserver:
 * денежную логику PaymentObserver не трогаем и внутрь него не лезем.
 *
 * РАНГ 4 лестницы полномочий (docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2):
 * читаем СОСТОЯВШИЙСЯ денежный переход и пишем ТОЛЬКО в deals/deal_transitions.
 * Ни доступа, ни цены, ни статуса платежа, ни статуса лида этот класс не меняет.
 * Lead::markConverted() отсюда НЕ вызывается — это делает Payment на своих
 * трёх путях (депозит/пробное/марафон), и обычная покупка курса лид не
 * конвертирует (спека §2.4). Мост на это поведение не опирается и его не чинит.
 *
 * Три факта из §2.3, на которых построен класс:
 *  1. доступ выдаётся из Payment::booted() → fireOnPaid(), ДО обсерверов, —
 *     значит к моменту нашего вызова денежный переход уже завершён;
 *  2. created/updated в PaymentObserver срабатывают на ЛЮБУЮ платную строку,
 *     включая бухгалтерские, — набор исключений приходится повторять здесь
 *     самим (qualifiesAsSale ниже);
 *  3. предикат события — wasChanged('status'), как у двух соседних аддитивных
 *     обсерверов (развилка F4 спеки §7, решена в пользу wasChanged).
 */
class PaymentDealBridgeObserver
{
    /** Статусы отката платежа — зеркало реверса в PaymentObserver (строки 71–76). */
    private const REVERSAL_STATUSES = ['failed', 'canceled', 'cancelled'];

    /** Платёж может родиться сразу paid (ручное создание, нулевая оплата промокодом). */
    public function created(Payment $payment): void
    {
        $this->sync($payment);
    }

    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('status')) {
            return;
        }

        $this->sync($payment);
    }

    private function sync(Payment $payment): void
    {
        // Флаг ВЫКЛ по умолчанию → на проде мост инертен.
        if (! config('features.crm_pipeline_board')) {
            return;
        }

        if (in_array($payment->status, self::REVERSAL_STATUSES, true)) {
            $this->reopenDealClosedBy($payment);

            return;
        }

        if (! $this->qualifiesAsSale($payment)) {
            return;
        }

        $this->closeOrRecordDeal($payment);
    }

    /**
     * Тот же набор исключений, что применяет Payment::fireOnPaid, плюс
     * is_conditional. Бухгалтерская строка, депозит, пробное занятие и
     * марафон «с проверкой» продажей курса не являются и сделку не закрывают.
     */
    private function qualifiesAsSale(Payment $payment): bool
    {
        return in_array($payment->status, Payment::PAID_STATUSES, true)
            && ! $payment->isExpense()
            && ! $payment->isSalaryPayout()
            && ! $payment->isDeposit()
            && ! $payment->isTrial()
            && ! $payment->isMarathonPaid()
            && ! $payment->is_conditional;
    }

    private function closeOrRecordDeal(Payment $payment): void
    {
        // Идемпотентность: повторная доставка вебхука / повторный save не
        // должны плодить вторую сделку по тому же платежу.
        if (Deal::query()->where('source_payment_id', $payment->id)->exists()) {
            return;
        }

        $won = DealStage::won();
        if ($won === null) {
            return;
        }

        $deal = $this->findOpenDealFor($payment);

        if ($deal !== null) {
            $deal->update(['source_payment_id' => $payment->id]);
            $deal->moveToStage($won, null, Deal::REASON_WON);

            return;
        }

        // Открытой сделки нет (прямая покупка без ведения по воронке) —
        // фиксируем состоявшуюся продажу сразу выигранной сделкой, иначе
        // отчётность по воронке слепа к прямым продажам.
        $deal = Deal::create([
            'lead_id' => $payment->lead_id,
            'user_id' => $payment->user_id,
            'course_id' => $payment->course_id,
            'amount' => $payment->amount ?? 0,
            'currency' => 'RUB',
            'stage_id' => $won->id,
            'closed_at' => now(),
            'closed_reason' => Deal::REASON_WON,
            'source_payment_id' => $payment->id,
        ]);

        DealTransition::create([
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $won->id,
            'user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Сопоставление платежа с уже ведущейся сделкой. lead_id — приоритетный
     * ключ (так тикет и сформулирован), но спека сама отмечает в §4.1, что
     * payments.lead_id почти всегда пуст, поэтому есть запасной путь по
     * user_id + course_id. Ничего не пишем — только ищем.
     */
    private function findOpenDealFor(Payment $payment): ?Deal
    {
        if ($payment->lead_id) {
            return Deal::query()->open()
                ->where('lead_id', $payment->lead_id)
                ->oldest()
                ->first();
        }

        if ($payment->user_id) {
            return Deal::query()->open()
                ->where('user_id', $payment->user_id)
                ->when($payment->course_id, fn ($q) => $q->where('course_id', $payment->course_id))
                ->oldest()
                ->first();
        }

        return null;
    }

    /**
     * Реверс: платёж откатили из paid. Продажи не было — сделка, закрытая
     * этим платежом, возвращается в первую стадию воронки и снова считается
     * открытой. Ранг 1 прав, сделка была устаревшей (спека §2.1).
     */
    private function reopenDealClosedBy(Payment $payment): void
    {
        $deal = Deal::query()->where('source_payment_id', $payment->id)->first();
        if ($deal === null) {
            return;
        }

        $first = DealStage::first();
        if ($first === null) {
            return;
        }

        // Снимаем ключ идемпотентности: если платёж позже снова станет paid,
        // сделка должна закрыться заново.
        $deal->update(['source_payment_id' => null]);
        $deal->moveToStage($first, null);
    }
}
