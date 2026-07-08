<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseBlock;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Признание выручки по методу начисления (accrual) — раскладка суммы платежа по
 * календарным месяцам. Лекарство от синдрома «прибыль есть, а денег нет»: годовой
 * курс, оплаченный сразу, признаётся не целиком в месяц оплаты (кассовый метод),
 * а долями по месяцам идущих блоков.
 *
 * АЛГОРИТМ — тот же, что в начислении ЗП преподавателям
 * (TeacherSalaryService::recognizedShares): платёж раскладывается на оплаченные
 * блоки (start_block/end_block, либо все блоки курса для 'full'/депозита), доля
 * за блок признаётся в месяце CourseBlock.starts_at; fallback — месяц created_at.
 * Override: salary_recognition_month (YYYY-MM) признаёт всю сумму в одном месяце.
 * Держим ОТДЕЛЬНЫЙ сервис (а не тянем private-метод ЗП), чтобы денежно-критичный
 * TeacherSalaryService не трогать; при будущей унификации — свести в один
 * источник (см. docs/revenue-recognition.md).
 *
 * Инвариант: Σ долей одного платежа == сумме платежа. Поэтому Σ признанной
 * выручки за всё время == Σ кассовой выручки (revenueForWindow) — вкладки ОПиУ
 * сходятся, accrual лишь перераспределяет ту же сумму между месяцами.
 *
 * Кэши блоков request-scoped — сервис резолвится свежим на каждый HTTP-запрос.
 */
class RevenueRecognitionService
{
    /**
     * Тарифы, не образующие выручку курса: системные расходы/возвраты и зеркала
     * выплат ЗП. Синхронно с FinanceCockpitReport::NON_REVENUE_TARIFFS и
     * TeacherSalaryService::NON_REVENUE_TARIFFS.
     */
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    /** @var array<int, list<int>>  course_id => отсортированные номера блоков */
    private array $blockNumbersCache = [];

    /** @var array<int, array<int, string>>  course_id => [block_number => 'Y-m'] (только с датой) */
    private array $blockMonthsCache = [];

    /**
     * Образует ли платёж выручку курса для признания. Тот же набор, что кассовая
     * выручка FinanceCockpitReport::revenueForWindow (paid + real + schoolReceived
     * + не расход/зеркало ЗП), плюс amount > 0 (нулевой бесплатный доступ не даёт
     * строк — на сумму признания не влияет). Депозиты/пробные ВХОДЯТ: это реальные
     * деньги курса (при покупке зачитываются, двойного счёта нет).
     */
    public function isRevenuePayment(Payment $payment): bool
    {
        return in_array($payment->status, Payment::PAID_STATUSES, true)
            && ! $payment->is_conditional
            && $payment->received_account === Payment::RECEIVED_SCHOOL
            && ! in_array($payment->tariff, self::NON_REVENUE_TARIFFS, true)
            && (float) $payment->amount > 0;
    }

    /**
     * Раскладка суммы платежа по месяцам признания: ['YYYY-MM' => сумма].
     * Пусто, если платёж не образует выручку (см. isRevenuePayment).
     *
     * @return array<string, float>
     */
    public function sharesForPayment(Payment $payment): array
    {
        if (! $this->isRevenuePayment($payment)) {
            return [];
        }

        $amount = (float) $payment->amount;

        // Ручной override месяца признания — вся сумма в один месяц.
        if ($payment->salary_recognition_month) {
            return [$payment->salary_recognition_month => $amount];
        }

        $createdMonth = $payment->created_at?->format('Y-m') ?? now()->format('Y-m');

        $courseId = $payment->course_id ? (int) $payment->course_id : null;
        $blockNumbers = $courseId ? $this->blockNumbersFor($courseId) : [];
        $covered = $this->coveredBlockNumbers($payment, $blockNumbers);

        // Платёж без курса/блоков (разовый, короткий продукт) — признаём в месяц оплаты.
        if (empty($covered)) {
            return [$createdMonth => $amount];
        }

        $blockMonths = $this->blockMonthsFor($courseId);
        $share = $amount / count($covered);

        $out = [];
        foreach ($covered as $n) {
            $month = $blockMonths[$n] ?? $createdMonth;
            $out[$month] = ($out[$month] ?? 0.0) + $share;
        }

        return $out;
    }

    /**
     * Номера блоков, которые покрывает платёж. (null,null) → все блоки курса
     * (full/депозит/legacy). Переиспользуем DebtorsReport::paymentCovers (тот же
     * предикат, что в ЗП). Если по списку блоков курса ничего не совпало (платёж
     * за блоки сверх заведённых) — берём прямой диапазон start..end.
     *
     * @param  list<int>  $blockNumbers
     * @return list<int>
     */
    private function coveredBlockNumbers(Payment $payment, array $blockNumbers): array
    {
        $start = $payment->start_block;
        $end = $payment->end_block;

        if ($start === null && $end === null) {
            return $blockNumbers;
        }

        $covered = array_values(array_filter(
            $blockNumbers,
            fn (int $n) => DebtorsReport::paymentCovers($start, $end, $n),
        ));

        if (! empty($covered)) {
            return $covered;
        }

        $s = $start ?? $end;
        $e = $end ?? $start;
        if ($s !== null) {
            return range(min($s, $e), max($s, $e));
        }

        return [];
    }

    /**
     * Отсортированные номера блоков курса (из CourseBlock). Пусто → платежи без
     * start/end раскладываются в месяц оплаты (нет заведённых блоков — нечего
     * растягивать).
     *
     * @return list<int>
     */
    private function blockNumbersFor(int $courseId): array
    {
        return $this->blockNumbersCache[$courseId] ??= CourseBlock::query()
            ->where('course_id', $courseId)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Месяц начала каждого датированного блока курса: [block_number => 'Y-m'].
     *
     * @return array<int, string>
     */
    private function blockMonthsFor(int $courseId): array
    {
        return $this->blockMonthsCache[$courseId] ??= CourseBlock::query()
            ->where('course_id', $courseId)
            ->whereNotNull('starts_at')
            ->get(['number', 'starts_at'])
            ->mapWithKeys(fn (CourseBlock $b) => [(int) $b->number => Carbon::parse($b->starts_at)->format('Y-m')])
            ->all();
    }
}
