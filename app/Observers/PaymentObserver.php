<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SendPaymentToSheetJob;
use App\Models\Payment;

class PaymentObserver
{
    /**
     * Статусы, которые считаем успешной оплатой.
     * Единый источник истины — Payment::PAID_STATUSES.
     */
    private const SUCCESS_STATUSES = Payment::PAID_STATUSES;

    /**
     * Новая запись. Синкаем, только если она сразу создана как paid
     * (ручное создание через Filament или нулевая оплата промокодом).
     */
    public function created(Payment $payment): void
    {
        if ($this->isSyncable($payment)) {
            SendPaymentToSheetJob::dispatch($payment->id, 'create');
        }

        // Платёж сразу создан как paid → наградить пригласившего (если есть).
        if (in_array($payment->status, self::SUCCESS_STATUSES, true)) {
            app(\App\Services\ReferralService::class)->rewardForPayment($payment);
            // Партнёрская программа (B2B) — независимая от студенческой (no-op, если выключена).
            app(\App\Services\PartnerService::class)->rewardForPayment($payment);
        }
    }

    /**
     * Изменение статуса. Основной кейс: webhook Точки pending -> paid.
     * Второстепенный: правка суммы/блоков в админке уже по оплаченной транзакции.
     */
    public function updated(Payment $payment): void
    {
        // Если только что стал paid (pending -> paid) — это основной кейс
        $justBecamePaid = $payment->isDirty('status')
            && in_array($payment->status, self::SUCCESS_STATUSES, true);

        // Если уже был paid и изменилось что-то значимое (сумма, блоки, курс)
        $stillPaidAndChanged = ! $payment->isDirty('status')
            && in_array($payment->status, self::SUCCESS_STATUSES, true)
            && $payment->isDirty(['amount', 'start_block', 'end_block', 'course_id', 'tariff']);

        if (($justBecamePaid || $stillPaidAndChanged) && $this->isSyncable($payment)) {
            SendPaymentToSheetJob::dispatch($payment->id, 'update');
        }

        // Основной кейс: вебхук Точки перевёл платёж в paid → награда рефереру.
        if ($justBecamePaid) {
            app(\App\Services\ReferralService::class)->rewardForPayment($payment);
            // Партнёрская программа (B2B) — независимая от студенческой (no-op, если выключена).
            app(\App\Services\PartnerService::class)->rewardForPayment($payment);
        }

        // Реверс: платёж откатили из paid (вебхук отмены/возврата или правка в
        // админке) → снять начисленную рефереру награду и освободить разовый слот.
        if ($payment->isDirty('status')
            && in_array($payment->status, ['failed', 'canceled', 'cancelled'], true)) {
            app(\App\Services\ReferralService::class)->reverseRewardForPayment($payment);
            // Зеркально снимаем ещё не выплаченное партнёрское вознаграждение.
            app(\App\Services\PartnerService::class)->reverseRewardForPayment($payment);
        }
    }

    /**
     * Общий фильтр для выгрузки в финансовый Google Sheet:
     * - только paid/success;
     * - не conditional (доступ «под честное слово» — не реальная транзакция).
     *
     * Сумму НЕ проверяем: нулевые оплаты (бесплатный доступ, 100% промокод,
     * студент, заведённый из таблицы с суммой 0) — тоже реальные операции и
     * должны попадать в таблицу. Расходы/возвраты и выплаты ЗП преподам
     * (salary_payout) идут с отрицательной суммой — тоже учитываются и льются
     * в ту же таблицу (label = «👨‍🏫 Выплата преподавателю», teacher в
     * transaction_id). Отсекаем только «обещанный» доступ (is_conditional) —
     * это не реальная транзакция.
     */
    private function isSyncable(Payment $payment): bool
    {
        // Нулевые access-only siblings (мульти-блочный доступ, BlockAccessMaterializer)
        // — не реальная транзакция, а «дорисовка ключа»; в финансовый лист не идут.
        // Нулевые ЛЕГИТИМНЫЕ оплаты (бесплатный доступ, 100%-промокод) синкаются —
        // отсекаем строго по маркеру transaction_id, а не по amount==0.
        if (is_string($payment->transaction_id)
            && str_starts_with($payment->transaction_id, \App\Services\BlockAccessMaterializer::GRANT_PREFIX)) {
            return false;
        }

        return in_array($payment->status, self::SUCCESS_STATUSES, true)
            && ! $payment->is_conditional;
    }
}
