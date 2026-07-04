<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentPromise;
use Illuminate\Support\Facades\DB;

/**
 * Авто-закрытие обещания оплаты, когда должник погасил его САМ через
 * self-service (DebtPaymentController) и реальный платёж пришёл по вебхуку
 * Точки. Вызывается из Payment::processSuccessfulPayment() для реального
 * (не conditional) платежа.
 *
 * В отличие от PromiseFulfillment::fulfil() (создаёт новый платёж под ручное
 * «Подтвердить оплату» куратора) — здесь платёж УЖЕ существует, мы лишь
 * привязываем к нему обещание(я) и переводим их в fulfilled.
 *
 * Идемпотентно: уже fulfilled/cancelled обещания пропускаем; conditional
 * платёж (доступ под честное слово, денег нет) сюда не заходит.
 */
class PromiseAutoFulfiller
{
    public function __construct(private readonly CuratorNotifier $curator) {}

    /**
     * @return int сколько обещаний закрыто
     */
    public function handlePaidPayment(Payment $payment): int
    {
        // Только реальные деньги. Self-service помечает свой платёж
        // linked_promise_id при is_conditional=false — это наш маркер.
        if ($payment->is_conditional || $payment->linked_promise_id === null) {
            return 0;
        }

        if (! in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return 0;
        }

        /** @var PaymentPromise|null $lead */
        $lead = PaymentPromise::find($payment->linked_promise_id);
        if ($lead === null) {
            return 0;
        }

        // Стоимость, зачтённая в долг = наличные (payment->amount, уже за вычетом
        // праны) + рублёвый эквивалент списанной праны. Иначе оплата с праной
        // недозакрыла бы обещания на сумму скидки.
        $debtValue = (float) $payment->amount;
        if ((int) $payment->prana_spent > 0) {
            $debtValue += (float) app(\App\Services\Prana\PranaService::class)
                ->pranaToRubles((int) $payment->prana_spent);
        }

        $toClose = $this->promisesToClose($lead, $debtValue);
        if ($toClose->isEmpty()) {
            return 0;
        }

        $closed = 0;
        DB::transaction(function () use ($toClose, $payment, &$closed): void {
            foreach ($toClose as $promise) {
                // Гонка/повтор вебхука: перечитываем под локом и повторно
                // проверяем, что обещание ещё не закрыто.
                $fresh = PaymentPromise::query()->whereKey($promise->id)->lockForUpdate()->first();
                if ($fresh === null || ! $fresh->isUnmet()) {
                    continue;
                }

                $fresh->update([
                    'status' => PaymentPromise::STATUS_FULFILLED,
                    'fulfilled_at' => now(),
                    'fulfilled_payment_id' => $payment->id,
                ]);
                $closed++;
            }
        });

        // Уведомляем куратора уже после коммита — по одному на закрытое обещание.
        if ($closed > 0) {
            foreach ($toClose as $promise) {
                $promise->refresh();
                if ($promise->status === PaymentPromise::STATUS_FULFILLED
                    && $promise->fulfilled_payment_id === $payment->id) {
                    $this->curator->promiseFulfilled($promise, $payment);
                }
            }
        }

        return $closed;
    }

    /**
     * Какие обещания закрывает этот платёж — greedy по графику: закрываем самые
     * ранние непогашенные обещания, чью НАКОПЛЕННУЮ сумму платёж покрывает целиком
     * (допуск 1 ₽). Единый механизм для next(1) / partial(K) / whole(все):
     *  - одиночное обещание → [lead];
     *  - рассрочка: идём от ранних к поздним, пока хватает денег.
     *
     * @return \Illuminate\Support\Collection<int, PaymentPromise>
     */
    private function promisesToClose(PaymentPromise $lead, float $paidAmount): \Illuminate\Support\Collection
    {
        if (! $lead->isUnmet()) {
            return collect();
        }

        if ($lead->installment_group_id === null) {
            return collect([$lead]);
        }

        $unmetGroup = PaymentPromise::query()
            ->inGroup($lead->installment_group_id)
            ->whereIn('status', [PaymentPromise::STATUS_ACTIVE, PaymentPromise::STATUS_EXPIRED])
            ->orderBy('promised_at')
            ->get();

        $toClose = collect();
        $running = 0.0;
        foreach ($unmetGroup as $promise) {
            $running += (float) ($promise->amount ?? 0);
            if ($running <= $paidAmount + 1.0) {
                $toClose->push($promise);
            } else {
                break; // денег на этот взнос уже не хватает — дальше тем более
            }
        }

        // Платёж всегда закрывает как минимум привязанное (lead) обещание — оно
        // самое раннее и его сумму студент точно внёс (контроллер это гарантирует).
        if ($toClose->isEmpty()) {
            $toClose->push($lead);
        }

        return $toClose;
    }
}
