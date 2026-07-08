<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\RevenueSchedule;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ведёт субледжер признания выручки (revenue_schedules) поверх платежей:
 * генерирует/пересобирает строки на изменение платежа и отдаёт агрегаты для
 * ОПиУ-начисления и отложенной выручки. Алгоритм раскладки — в
 * RevenueRecognitionService; здесь — только персистентность и своды.
 *
 * Идемпотентно: regenerateFor удаляет старые строки платежа и вставляет
 * актуальные. Платёж, переставший быть выручкой (возврат, отмена, conditional,
 * прямой платёж преподавателю), просто теряет строки — субледжер самоочищается.
 */
class RevenueScheduleService
{
    public function __construct(private readonly RevenueRecognitionService $recognition) {}

    /**
     * Пересобрать строки признания для одного платежа (delete + insert).
     * Единая точка для обсёрвера и бэкофилла.
     */
    public function regenerateFor(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            RevenueSchedule::query()->where('payment_id', $payment->id)->delete();

            $shares = $this->resolveShares($payment);
            if (empty($shares)) {
                return;
            }

            $now = now();
            $rows = [];
            foreach ($shares as $month => $amount) {
                $rows[] = [
                    'payment_id' => $payment->id,
                    'period_month' => $month,
                    'amount' => Money::round($amount),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            RevenueSchedule::query()->insert($rows);
        });
    }

    /**
     * Строки признания платежа с учётом реверса возврата (H352).
     *
     * Дефолт (флаг revenue.reverse_unrecognized_on_refund = false): как раньше —
     * полная раскладка sharesForPayment, возврат отдельным «Расход» её не трогает.
     *
     * Флаг включён И у платежа есть привязанный возврат (Payment.refund_of_payment_id
     * → этот платёж): раскладку усекаем по месяц ПЕРВОГО возврата включительно —
     * уже оказанная услуга остаётся выручкой, непризнанный будущий остаток
     * отбрасывается. Решение MG 08-07-2026 (H258 @DECIDE #2, модель «отдельный
     * Расход, заработанное остаётся»).
     *
     * @return array<string, float>
     */
    private function resolveShares(Payment $payment): array
    {
        $shares = $this->recognition->sharesForPayment($payment);

        if (empty($shares) || ! config('revenue.reverse_unrecognized_on_refund')) {
            return $shares;
        }

        $earliestRefundAt = Payment::query()
            ->where('refund_of_payment_id', $payment->id)
            ->min('created_at');

        if ($earliestRefundAt === null) {
            return $shares;
        }

        $refundMonth = Carbon::parse($earliestRefundAt)->format('Y-m');

        // Строковое сравнение корректно для 'YYYY-MM' (лексикографика = хронология).
        return array_filter(
            $shares,
            fn (string $month) => $month <= $refundMonth,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Пересобрать субледжер по ВСЕМ платежам (бэкофилл истории). Чанками, чтобы
     * не держать всё в памяти. Возвращает статистику для команды/лога.
     *
     * @return array{payments:int, rows:int}
     */
    public function backfillAll(): array
    {
        $payments = 0;
        $rows = 0;

        Payment::query()->orderBy('id')->chunkById(500, function ($chunk) use (&$payments, &$rows) {
            foreach ($chunk as $payment) {
                $this->regenerateFor($payment);
                $payments++;
                $rows += RevenueSchedule::query()->where('payment_id', $payment->id)->count();
            }
        });

        return ['payments' => $payments, 'rows' => $rows];
    }

    /**
     * Признанная выручка за конкретный месяц 'YYYY-MM' (сумма строк субледжера).
     * Это выручка ОПиУ-начисления за период.
     */
    public function recognizedForMonth(string $period): float
    {
        return Money::round((float) RevenueSchedule::query()
            ->where('period_month', $period)
            ->sum('amount'));
    }

    /**
     * Признанная выручка накопительно по конец месяца $period включительно
     * (все строки с period_month <= $period). Сравнение строковое корректно для
     * формата 'YYYY-MM' (лексикографический порядок = хронологический).
     */
    public function recognizedThroughMonth(string $period): float
    {
        return Money::round((float) RevenueSchedule::query()
            ->where('period_month', '<=', $period)
            ->sum('amount'));
    }

    /**
     * Отложенная выручка на конец месяца $period: получено кассой − признано
     * (накопительно). Классическое «оплачено, но ещё не отработано» — обязательство,
     * тот самый «замороженный» разрыв между кассой и прибылью годового курса.
     *
     * Положительное = аванс за неоказанную услугу (норма для предоплаты вперёд).
     * Отрицательное = признано раньше кассы (поздняя оплата уже прошедших блоков) —
     * по сути начисленная, но не полученная выручка; помечаем в UI отдельно.
     *
     * Реверс возврата (H352, флаг revenue.reverse_unrecognized_on_refund): из
     * кассы вычитаем возвращённое ученикам (привязанные возвраты refund_of_payment_id),
     * иначе отложенная выручка завышала бы обязательство на уже возвращённую сумму
     * (признание при этом усечено по месяц возврата — см. resolveShares).
     *
     * @return array{cashReceived:float, refundsReturned:float, recognized:float, deferred:float}
     */
    public function deferredRevenueAsOf(string $period): array
    {
        $end = Carbon::parse($period.'-01')->endOfMonth();

        // Касса, полученная по конец периода: тот же набор, что образует выручку
        // (см. RevenueRecognitionService::isRevenuePayment), по дате платежа.
        $cashReceived = Money::round((float) Payment::query()
            ->paid()
            ->real()
            ->schoolReceived()
            ->whereNotIn('tariff', ['Расход', 'salary_payout'])
            ->where('amount', '>', 0)
            ->where('created_at', '<=', $end)
            ->sum('amount'));

        // Возвращённое ученикам по конец периода (привязанные возвраты). Только при
        // включённом реверсе — иначе поведение инертно (дефолт до H352).
        $refundsReturned = 0.0;
        if (config('revenue.reverse_unrecognized_on_refund')) {
            $refundsReturned = Money::round((float) Payment::query()
                ->whereNotNull('refund_of_payment_id')
                ->where('created_at', '<=', $end)
                ->sum(DB::raw('ABS(amount)')));
        }

        $recognized = $this->recognizedThroughMonth($period);

        return [
            'cashReceived' => $cashReceived,
            'refundsReturned' => $refundsReturned,
            'recognized' => $recognized,
            'deferred' => Money::round($cashReceived - $refundsReturned - $recognized),
        ];
    }
}
