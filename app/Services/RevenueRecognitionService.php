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
 * Общее ядро «блок → месяц» (покрытые блоки + раскладка по месяцам) сведено в
 * BlockMonthRecognition (H349); оба сервиса делегируют его. Резолюция блоков курса
 * и request-scoped кэши остаются здесь и в TeacherSalaryService раздельно —
 * денежно-критичный кэш ЗП не тронут (см. docs/revenue-recognition.md).
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

    /** @var array<int, array<int, string>>  course_id => [block_number => 'Y-m-d'] (только с датой) */
    private array $blockStartDatesCache = [];

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
        return $this->attributionForPayment($payment)['shares'];
    }

    /**
     * Та же раскладка, но С НАЗВАННЫМ МЕХАНИЗМОМ атрибуции (H3951): по колонке
     * salary_recognition_month, по месяцам блоков, по дате платежа или по
     * названному fallback вырожденного расписания. Публична ради аудита
     * (recognition:attribution-audit) — отчёт обязан уметь сказать по каждой
     * строке, чем она признана.
     *
     * @return array{shares: array<string, float>, mechanism: string, degenerate: bool}
     */
    public function attributionForPayment(Payment $payment, ?bool $degenerateGuard = null): array
    {
        if (! $this->isRevenuePayment($payment)) {
            return ['shares' => [], 'mechanism' => BlockMonthRecognition::BY_CREATED, 'degenerate' => false];
        }

        $amount = (float) $payment->amount;
        $createdMonth = $payment->created_at?->format('Y-m') ?? now()->format('Y-m');

        $courseId = $payment->course_id ? (int) $payment->course_id : null;
        $blockNumbers = $courseId ? $this->blockNumbersFor($courseId) : [];
        $covered = BlockMonthRecognition::coveredBlockNumbers($payment->start_block, $payment->end_block, $blockNumbers);

        return BlockMonthRecognition::attribute(
            $amount,
            $payment->salary_recognition_month,
            $covered,
            $courseId ? $this->blockMonthsFor($courseId) : [],
            $courseId ? $this->blockStartDatesFor($courseId) : [],
            $createdMonth,
            $degenerateGuard ?? BlockMonthRecognition::degenerateGuardEnabled(),
        );
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
     * Дата начала каждого датированного блока курса: [block_number => 'Y-m-d'].
     * Нужна сторожу вырожденного расписания (H3951): вырождение видно по ДАТЕ,
     * месяц слишком груб — настоящий курс из четырёх блоков в одном месяце
     * вырожденным не является.
     *
     * @return array<int, string>
     */
    private function blockStartDatesFor(int $courseId): array
    {
        return $this->blockStartDatesCache[$courseId] ??= CourseBlock::query()
            ->where('course_id', $courseId)
            ->whereNotNull('starts_at')
            ->get(['number', 'starts_at'])
            ->mapWithKeys(fn (CourseBlock $b) => [(int) $b->number => Carbon::parse($b->starts_at)->toDateString()])
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
