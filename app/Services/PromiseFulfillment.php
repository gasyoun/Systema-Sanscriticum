<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendPaymentToSheetJob;
use App\Models\Payment;
use App\Models\PaymentPromise;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromiseFulfillment
{
    /**
     * Закрытие обещания с автоматическим созданием Payment.
     *
     * В обычном режиме платёж сохраняется обычным `create()` — срабатывают
     * штатные хуки (`Payment::booted::created`): grantAccess, welcomeEmail-if-first,
     * Google Sheets sync, TG-уведомление студенту.
     *
     * В silent-режиме платёж пишется через `Payment::withoutEvents`, после чего
     * руками вызываются только тихие хуки: grantAccess (идемпотентен) +
     * awardPrana (защищён) + Google Sheets sync (аудит). TG-уведомление и
     * welcomeEmail подавляются — это важно для случая «фиксирую факт оплаты
     * задним числом» или «студент ещё не оплатил, но я договорился, обещание
     * закрываю формально».
     *
     * @param  array{amount: numeric, start_block: int|numeric, end_block: int|numeric, transaction_id?: ?string}  $data
     */
    public function fulfil(PaymentPromise $promise, array $data, bool $silent = false): Payment
    {
        $amount = (float) ($data['amount'] ?? 0);
        $startBlock = (int) ($data['start_block'] ?? 0);
        $endBlock = (int) ($data['end_block'] ?? 0);
        $transactionId = trim((string) ($data['transaction_id'] ?? '')) !== ''
            ? (string) $data['transaction_id']
            : 'promise_#'.$promise->id;

        $this->validate($amount, $startBlock, $endBlock);

        return DB::transaction(function () use ($promise, $amount, $startBlock, $endBlock, $transactionId, $silent) {
            /** @var PaymentPromise $lockedPromise */
            $lockedPromise = PaymentPromise::query()
                ->whereKey($promise->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPromise->status === PaymentPromise::STATUS_FULFILLED
                || $lockedPromise->fulfilled_payment_id !== null) {
                throw ValidationException::withMessages([
                    'promise' => 'Обещание уже закрыто оплатой.',
                ]);
            }

            $payload = [
                'user_id' => $lockedPromise->user_id,
                'course_id' => $lockedPromise->course_id,
                'amount' => $amount,
                'tariff' => 'block_'.$startBlock,
                'status' => 'paid',
                'start_block' => $startBlock,
                'end_block' => $endBlock,
                'transaction_id' => $transactionId,
                // H1645: создаётся уже оплаченным — на silent-пути (withoutEvents)
                // Payment::booted::created (fireOnPaid) не срабатывает, значит
                // некому проставить first_paid_at. Пишем прямо в payload — общий
                // для обеих веток, на не-silent пути fireOnPaid просто увидит
                // непустое значение и не тронет его повторно.
                'first_paid_at' => now(),
            ];

            if ($silent) {
                /** @var Payment $payment */
                $payment = Payment::withoutEvents(fn () => Payment::create($payload));
                $this->runSilentHooks($payment, $lockedPromise);
            } else {
                $payment = Payment::create($payload);
            }

            $lockedPromise->update([
                'status' => PaymentPromise::STATUS_FULFILLED,
                'fulfilled_at' => now(),
                'fulfilled_payment_id' => $payment->id,
            ]);

            // Keep the caller's instance coherent even though the authoritative
            // write used the row-locked reload above.
            $promise->setRawAttributes($lockedPromise->getAttributes(), true);

            return $payment;
        });
    }

    private function validate(float $amount, int $startBlock, int $endBlock): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма должна быть больше нуля.']);
        }
        if ($startBlock <= 0 || $endBlock <= 0) {
            throw ValidationException::withMessages(['start_block' => 'Укажите корректные номера блоков.']);
        }
        if ($startBlock > $endBlock) {
            throw ValidationException::withMessages(['end_block' => 'Конечный блок не может быть меньше начального.']);
        }
    }

    /**
     * Тихие хуки: только то, что должно случиться без шума для студента.
     * НЕ вызываем TG-уведомление и welcomeEmail.
     */
    private function runSilentHooks(Payment $payment, PaymentPromise $promise): void
    {
        $payment->grantAccess();
        $payment->awardPranaForPurchase();

        if ((float) $payment->amount > 0 && in_array($payment->status, Payment::PAID_STATUSES, true)) {
            SendPaymentToSheetJob::dispatch($payment->id, 'create');
        }

        // Silent: студенту не пишем, но кураторов уведомляем — для них это
        // штатное событие «обещание закрыто оплатой». В не-silent режиме
        // уведомление шлёт Payment::processSuccessfulPayment (paymentPaid).
        app(CuratorNotifier::class)->promiseFulfilled($promise, $payment);
    }
}
