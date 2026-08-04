<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Services\Prana\PranaService;
use Illuminate\Support\Collection;
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
            $debtValue += (float) app(PranaService::class)
                ->pranaToRubles((int) $payment->prana_spent);
        }

        $toClose = $this->coveredPromises($lead, $debtValue);
        if ($toClose->isEmpty()) {
            return 0;
        }

        $closedPromiseIds = [];
        DB::transaction(function () use ($toClose, $payment, &$closedPromiseIds): void {
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
                    // fulfilled_payment_id is a unique audit link. A single
                    // self-service payment may cover several instalments, so
                    // only its explicitly linked lead promise owns the link;
                    // the additional covered rows remain nullable.
                    'fulfilled_payment_id' => $fresh->id === $payment->linked_promise_id
                        ? $payment->id
                        : null,
                ]);
                $closedPromiseIds[] = (int) $fresh->id;
            }
        });

        // Уведомляем куратора уже после коммита — по одному на закрытое обещание.
        if ($closedPromiseIds !== []) {
            foreach (PaymentPromise::query()->whereKey($closedPromiseIds)->get() as $promise) {
                $this->curator->promiseFulfilled($promise, $payment);
            }
        }

        return count($closedPromiseIds);
    }

    /**
     * Какие обещания покрывает этот платёж — greedy по графику: закрываем самые
     * ранние непогашенные обещания, чью НАКОПЛЕННУЮ сумму платёж покрывает целиком
     * (допуск 1 ₽). Единый механизм для next(1) / partial(K) / whole(все):
     *  - одиночное обещание → [lead];
     *  - рассрочка: идём от ранних к поздним, пока хватает денег.
     *
     * Публичный: тем же счётчиком DebtPaymentController решает, СКОЛЬКО блоков
     * открыть на чекауте (число покрытых взносов = число блоков снизу), чтобы
     * грант доступа и закрытие обещаний на вебхуке не расходились.
     *
     * @return Collection<int, PaymentPromise>
     */
    public function coveredPromises(PaymentPromise $lead, float $paidAmount): Collection
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
