<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SendPaymentToSheetJob;
use App\Models\Payment;

class PaymentObserver
{
    /**
     * Статусы, которые считаем успешной оплатой.
     * Держим в одном месте, чтобы не плодить магические строки.
     */
    private const SUCCESS_STATUSES = ['paid', 'success'];

    /**
     * Новая запись. Синкаем, только если она сразу создана как paid
     * (ручное создание через Filament или нулевая оплата промокодом).
     */
    public function created(Payment $payment): void
    {
        if ($this->isSyncable($payment)) {
            SendPaymentToSheetJob::dispatch($payment->id, 'create');
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
    }

    /**
     * Общий фильтр для выгрузки в финансовый Google Sheet:
     * - только paid/success;
     * - не conditional (доступ «под честное слово» — не реальная транзакция).
     *
     * Сумму НЕ проверяем: нулевые оплаты (бесплатный доступ, 100% промокод,
     * студент, заведённый из таблицы с суммой 0) — тоже реальные операции и
     * должны попадать в таблицу. Расходы/возвраты идут с отрицательной суммой
     * и тоже учитываются. Отсекаем «обещанный» доступ (is_conditional) и
     * выплаты ЗП преподам (salary_payout) — это не студенческая оплата, у них
     * свой учёт (отдельный лист — позже).
     */
    private function isSyncable(Payment $payment): bool
    {
        return in_array($payment->status, self::SUCCESS_STATUSES, true)
            && ! $payment->is_conditional
            && ! $payment->isSalaryPayout();
    }
}
