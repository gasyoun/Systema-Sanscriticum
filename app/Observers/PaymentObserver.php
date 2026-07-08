<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SendPaymentToSheetJob;
use App\Models\Payment;
use App\Services\BlockAccessMaterializer;
use App\Services\PartnerService;
use App\Services\ReferralService;
use App\Services\RevenueScheduleService;

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
            app(ReferralService::class)->rewardForPayment($payment);
            // Партнёрская программа (B2B) — независимая от студенческой (no-op, если выключена).
            app(PartnerService::class)->rewardForPayment($payment);
        }

        // График признания выручки (accrual) — субледжер поверх платежа.
        $this->syncRevenueSchedule($payment);
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
            app(ReferralService::class)->rewardForPayment($payment);
            // Партнёрская программа (B2B) — независимая от студенческой (no-op, если выключена).
            app(PartnerService::class)->rewardForPayment($payment);
        }

        // Реверс: платёж откатили из paid (вебхук отмены/возврата или правка в
        // админке) → снять начисленную рефереру награду и освободить разовый слот.
        if ($payment->isDirty('status')
            && in_array($payment->status, ['failed', 'canceled', 'cancelled'], true)) {
            app(ReferralService::class)->reverseRewardForPayment($payment);
            // Зеркально снимаем ещё не выплаченное партнёрское вознаграждение.
            app(PartnerService::class)->reverseRewardForPayment($payment);
        }

        // Пересобираем график признания выручки: сумма/блоки/курс/статус могли
        // измениться. Платёж, переставший быть выручкой (отмена/возврат), теряет
        // строки — субледжер самоочищается (см. RevenueScheduleService).
        $this->syncRevenueSchedule($payment);
    }

    /**
     * Удаление платежа-возврата снимает усечение с исходного платежа (H352):
     * его график признания пересобирается уже без этого возврата.
     *
     * Сам удалённый платёж НЕ пересобираем — его строки признания ушли каскадом
     * (revenue_schedules.payment_id cascadeOnDelete), а INSERT под удалённый
     * payment_id нарушил бы FK. Трогаем только исходный платёж возврата.
     */
    public function deleted(Payment $payment): void
    {
        if ($payment->refund_of_payment_id) {
            $original = Payment::find($payment->refund_of_payment_id);
            if ($original !== null) {
                app(RevenueScheduleService::class)->regenerateFor($original);
            }
        }
    }

    /**
     * Пересобрать график признания выручки для платежа И — если это возврат
     * (refund_of_payment_id) — для исходного платежа, чтобы усечение по месяц
     * возврата применилось/снялось (H352, флаг revenue.reverse_unrecognized_on_refund).
     */
    private function syncRevenueSchedule(Payment $payment): void
    {
        $service = app(RevenueScheduleService::class);
        $service->regenerateFor($payment);

        if ($payment->refund_of_payment_id) {
            $original = Payment::find($payment->refund_of_payment_id);
            if ($original !== null) {
                $service->regenerateFor($original);
            }
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
            && str_starts_with($payment->transaction_id, BlockAccessMaterializer::GRANT_PREFIX)) {
            return false;
        }

        return in_array($payment->status, self::SUCCESS_STATUSES, true)
            && ! $payment->is_conditional;
    }
}
