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

            $shares = $this->recognition->sharesForPayment($payment);
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
     * @return array{cashReceived:float, recognized:float, deferred:float}
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

        $recognized = $this->recognizedThroughMonth($period);

        return [
            'cashReceived' => $cashReceived,
            'recognized' => $recognized,
            'deferred' => Money::round($cashReceived - $recognized),
        ];
    }
}
