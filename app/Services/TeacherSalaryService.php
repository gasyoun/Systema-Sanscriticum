<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SalaryScheme;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\SalaryClosedPeriod;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Единый источник правды по начислению зарплаты преподавателям.
 *
 * Заменяет прежний Teacher::calculateEarnings (который остался тонкой обёрткой
 * ради back-compat) и исправляет его перекосы:
 *   A1 — депозиты (брони) и пробные ВХОДЯТ в базу (реальные деньги курса; при
 *        покупке зачитываются в цену, полный платёж пишется за их вычетом — без
 *        двойного счёта). В базу НЕ входят только возвраты и зеркала выплат;
 *   A2 — возвраты (tariff='Расход', отрицательная сумма) вычитаются из базы;
 *   A3 — флаг «брутто/нетто»: по умолчанию начисляем от фактически уплаченного.
 *
 * ПРИЗНАНИЕ ПО ПЕРИОДУ БЛОКА (accrual). ЗП признаётся НЕ по дате платежа, а по
 * периоду блока, который этот платёж оплачивает: платёж раскладывается на
 * оплаченные блоки (start_block/end_block или весь курс для full), доля за блок
 * признаётся в месяце, когда идёт блок (CourseBlock.starts_at). Это правильно
 * разносит оплату наперёд (вперёд) и просроченную (назад). Сумма за всё время
 * не меняется — accrual лишь перераспределяет её между месяцами.
 *
 * Override: если у платежа задан salary_recognition_month (YYYY-MM) — вся сумма
 * признаётся в этом месяце, без раскладки по блокам.
 *
 * NB: под accrual схемы 'percent' и 'percent_per_block' сходятся (full тоже
 * раскладывается по блокам) — это попутно исправляет прежний недосчёт full в
 * 'percent_per_block'.
 *
 * Кэши request-scoped — сервис резолвится свежим на каждый HTTP-запрос.
 */
class TeacherSalaryService
{
    /**
     * Тарифы, которые НЕ являются выручкой курса: возвраты и зеркала выплат.
     * Депозиты (брони) и пробные ВХОДЯТ в базу — это реальные деньги курса.
     * Двойного счёта нет: при покупке курса депозит/пробное зачитываются в
     * цену, и полный платёж пишется уже за их вычетом (Tariff::calculateFinalPriceForUser).
     *
     * Публичная — MutualSettlementService (H1730) исключает те же тарифы из
     * суммы «оплачено как ученик», и заводить там СВОЮ копию списка нельзя:
     * разъехавшись, две копии дали бы две разные «правды» об одних деньгах.
     */
    public const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    private const EXPENSE_TARIFF = 'Расход';

    /** @var array<int, int>  course_id => max block_number (>=1) */
    private array $maxBlockCache = [];

    /** @var array<int, list<int>>  course_id => отсортированные номера блоков */
    private array $blockNumbersCache = [];

    /** @var array<int, array<int, string>>  course_id => [block_number => 'Y-m'] (только с датой) */
    private array $blockMonthsCache = [];

    /** @var array<int, array<int, string>>  course_id => [block_number => 'Y-m-d'] (только с датой) */
    private array $blockStartDatesCache = [];

    /** @var array<int, Collection<int, Payment>>  course_id => все paid/non-conditional платежи */
    private array $coursePaymentsCache = [];

    /** @var array<string, array<string, mixed>>  "course_id|opts" => выручка курса (без условий препода) */
    private array $courseRevenueCache = [];

    /** @var array<string, array<string, mixed>>  "course_id|teacher_id|opts" => начисление пары (курс, препод) */
    private array $teacherCourseAccrualCache = [];

    /** @var array<string, array<int, mixed>>  кэш сводки по ключу периода */
    private array $summaryCache = [];

    /** @var array<int, list<string>>|null  teacher_id => закрытые месяцы 'Y-m' */
    private ?array $closedMonthsCache = null;

    /** @var array<int, array<string, Carbon>>|null  teacher_id => [period_month => closed_at] */
    private ?array $closedPeriodCache = null;

    /**
     * Человекочитаемые подписи схем оплаты курса.
     *
     * @return array<string, string>
     */
    public static function salaryTypeLabels(): array
    {
        return SalaryScheme::labels();
    }

    public static function salaryTypeLabel(?string $type): string
    {
        return SalaryScheme::labels()[$type] ?? ($type ?: '—');
    }

    /**
     * Технический курс (прочие затраты) — в начислении ЗП не участвует.
     */
    public static function isTechnicalCourse(Course $course): bool
    {
        return $course->slug === 'system-expenses'
            || $course->title === 'Прочие затраты (Технический)';
    }

    /**
     * Разбивка начисления по курсам преподавателя за окно [$start; $end]
     * (или за всё время, если даты не заданы). Суммы — признанные в этом окне.
     *
     * @param  array{gross_before_discount?: bool, subtract_returns?: bool}  $opts
     * @return array{rows: list<array<string, mixed>>, total: float}
     */
    public function breakdownForTeacher(Teacher $teacher, $start = null, $end = null, array $opts = []): array
    {
        $opts = $this->normalizeOptions($opts);
        $rows = [];
        $total = 0.0;

        foreach ($teacher->allTaughtCourses() as $course) {
            if (self::isTechnicalCourse($course)) {
                continue;
            }

            // Эффективные условия именно этого преподавателя на курсе (основной —
            // с курса, со-препод — из pivot). null = курс ему не начисляется.
            $terms = $course->salaryTermsFor((int) $teacher->id);
            if ($terms === null) {
                continue;
            }

            $data = $this->teacherCourseAccrual($course, (int) $teacher->id, $terms['type'], $terms['value'], $opts);

            $accrued = $this->windowSum($data['accrued'], $start, $end);
            $revenue = $this->windowSum($data['revenue'], $start, $end);
            $returns = $this->windowSum($data['returns'], $start, $end);
            $students = $this->studentsInWindow($data['students'], $start, $end);

            // Пропускаем курс, по которому в окне ничего не признано.
            if ($accrued == 0.0 && $revenue == 0.0 && $students === 0) {
                continue;
            }

            $rows[] = [
                'course_id' => $course->id,
                'title' => $course->title,
                'salary_type' => $terms['type'],
                'salary_value' => (float) $terms['value'],
                'students' => $students,
                'revenue' => Money::round($revenue),
                'returns' => Money::round($returns),
                'accrued' => Money::round($accrued),
            ];

            $total += $accrued;
        }

        return ['rows' => $rows, 'total' => Money::round($total)];
    }

    /**
     * Итоговое начисление преподавателю за окно — то, что отдаёт обёртка
     * Teacher::calculateEarnings().
     */
    public function totalForTeacher(Teacher $teacher, $start = null, $end = null, array $opts = []): float
    {
        return $this->breakdownForTeacher($teacher, $start, $end, $opts)['total'];
    }

    /**
     * Итоги начисления преподавателю за окно с разбивкой на валовое (без
     * возвратов), эффект возвратов и чистое.
     *
     * @return array{net: float, gross: float, returns: float}
     */
    public function periodTotals(Teacher $teacher, $start = null, $end = null, array $opts = []): array
    {
        $opts = $this->normalizeOptions($opts);
        $net = 0.0;
        $gross = 0.0;
        $returns = 0.0;

        foreach ($teacher->allTaughtCourses() as $course) {
            if (self::isTechnicalCourse($course)) {
                continue;
            }
            $terms = $course->salaryTermsFor((int) $teacher->id);
            if ($terms === null) {
                continue;
            }
            $data = $this->teacherCourseAccrual($course, (int) $teacher->id, $terms['type'], $terms['value'], $opts);
            $net += $this->windowSum($data['accrued'], $start, $end);
            $gross += $this->windowSum($data['accrued_gross'], $start, $end);
            $returns += $this->windowSum($data['accrued_returns'], $start, $end);
        }

        return ['net' => Money::round($net), 'gross' => Money::round($gross), 'returns' => Money::round($returns)];
    }

    /**
     * Возвраты/расходы преподавателя, признанные в окне — для тултипа «из чего
     * минус»: курс, сумма операции, её эффект на ЗП (для процентных схем), дата,
     * описание (transaction_id у импортированных расходов).
     *
     * @return list<array{course_title:string, amount:float, effect:float, date:?string, note:?string}>
     */
    public function returnsForTeacher(Teacher $teacher, $start = null, $end = null, array $opts = []): array
    {
        $opts = $this->normalizeOptions($opts);
        if (! $opts['subtract_returns']) {
            return [];
        }

        $lines = [];
        foreach ($teacher->allTaughtCourses() as $course) {
            if (self::isTechnicalCourse($course)) {
                continue;
            }
            $terms = $course->salaryTermsFor((int) $teacher->id);
            if ($terms === null) {
                continue;
            }
            $value = (float) $terms['value'];
            $isPercent = SalaryScheme::isPercentType($terms['type']);

            foreach ($this->coursePayments($course->id) as $p) {
                if (! $this->isReturnPayment($p)) {
                    continue;
                }
                $month = $p->salary_recognition_month ?: $p->created_at?->format('Y-m');
                if (! $month || ! $this->monthInWindow($month, $start, $end)) {
                    continue;
                }
                $amount = (float) $p->amount;
                $lines[] = [
                    'course_title' => (string) $course->title,
                    'amount' => Money::round($amount),
                    'effect' => Money::round($isPercent ? $amount * ($value / 100) : 0.0),
                    'date' => $p->created_at?->format('d.m.Y'),
                    'note' => $p->transaction_id,
                ];
            }
        }

        return $lines;
    }

    /**
     * Прямые платежи, полученные преподавателем НА ЛИЧНЫЙ СЧЁТ (минуя кассу школы),
     * признанные в окне — источник АВТО-ЗАЧЁТА в гонорар по номиналу в валюте
     * выплаты преподавателя. Признание — по дате чека (created_at) / override, как
     * у returnsForTeacher (это кассовое событие, не accrual по блокам). Из выручки
     * такие платежи уже исключены (computeCourseRevenue) — здесь они возвращаются
     * как вычет. Строки с валютой, не равной payout_currency преподавателя, в total
     * НЕ входят и помечаются mismatch=true (молча валюты не сводим).
     *
     * @return array{total: float, currency: ?string, lines: list<array{
     *     payment_id:int, course_id:int, course_title:string, amount:float,
     *     currency:?string, date:?string, payer_note:?string, student:?string,
     *     mismatch:bool}>}
     */
    public function directReceiptsForTeacher(Teacher $teacher, $start = null, $end = null): array
    {
        $payoutCurrency = $teacher->payout_currency;
        $lines = [];
        $total = 0.0;

        foreach ($teacher->allTaughtCourses() as $course) {
            if (self::isTechnicalCourse($course)) {
                continue;
            }

            foreach ($this->coursePayments($course->id) as $p) {
                if ($p->received_account !== Payment::RECEIVED_TEACHER
                    || (int) $p->received_by_teacher_id !== (int) $teacher->id) {
                    continue;
                }

                $month = $p->salary_recognition_month ?: $p->created_at?->format('Y-m');
                if (! $month || ! $this->monthInWindow($month, $start, $end)) {
                    continue;
                }

                $amount = (float) $p->foreign_amount;
                $currency = $p->foreign_currency;
                // Зачёт возможен только в валюте выплаты преподавателя.
                $mismatch = empty($payoutCurrency) || $currency !== $payoutCurrency || $amount <= 0;

                if (! $mismatch) {
                    $total += $amount;
                }

                $lines[] = [
                    'payment_id' => (int) $p->id,
                    'course_id' => (int) $course->id,
                    'course_title' => (string) $course->title,
                    'amount' => Money::round($amount),
                    'currency' => $currency,
                    'date' => $p->created_at?->format('d.m.Y'),
                    'payer_note' => $p->payer_note,
                    'student' => $p->user?->name ?? ($p->user_id ? '#'.$p->user_id : null),
                    'mismatch' => $mismatch,
                ];
            }
        }

        return ['total' => Money::round($total), 'currency' => $payoutCurrency, 'lines' => $lines];
    }

    /**
     * Итоговый номинал прямых платежей преподавателю за окно (в валюте выплаты) —
     * удобная обёртка над directReceiptsForTeacher()['total'].
     */
    public function directReceiptsTotal(Teacher $teacher, $start = null, $end = null): float
    {
        return $this->directReceiptsForTeacher($teacher, $start, $end)['total'];
    }

    /**
     * ID платежей-прямых-зачётов, уже вошедших в какую-либо выплату преподавателя
     * (снимок `direct_receipts` в breakdown). По образцу paidShareKeys(): источник
     * истины — сами выплаты, поэтому удаление выплаты автоматически «освобождает»
     * её зачёты (снимок исчезает вместе с payout). Так один прямой платёж не
     * зачитывается дважды.
     *
     * @return array<int, true> payment_id => true
     */
    public function settledDirectReceiptIds(Teacher $teacher): array
    {
        $ids = [];
        foreach ($teacher->payouts()->whereNotNull('breakdown')->get() as $payout) {
            foreach (($payout->breakdown['direct_receipts'] ?? []) as $line) {
                if (isset($line['payment_id'])) {
                    $ids[(int) $line['payment_id']] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * Прямые платежи преподавателю, готовые к зачёту в НОВОЙ выплате: в валюте
     * выплаты (mismatch отсечены) и ещё не вошедшие ни в одну выплату. Кандидаты
     * для пикера в калькуляторе.
     *
     * @return list<array{payment_id:int, course_id:int, course_title:string,
     *     amount:float, currency:?string, date:?string, payer_note:?string,
     *     student:?string, mismatch:bool}>
     */
    public function availableDirectReceipts(Teacher $teacher): array
    {
        $settled = $this->settledDirectReceiptIds($teacher);

        return array_values(array_filter(
            $this->directReceiptsForTeacher($teacher)['lines'],
            fn (array $l) => ! $l['mismatch'] && ! isset($settled[$l['payment_id']]),
        ));
    }

    /**
     * @return list<array{payout_id:int, amount:float, settled_amount:float, remaining:float, paid_at:?string}>
     */
    public function outstandingAdvanceItems(Teacher $teacher): array
    {
        return $teacher->payouts()
            ->unsettledAdvances()
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TeacherPayout $payout): array => [
                'payout_id' => (int) $payout->id,
                'amount' => Money::round((float) $payout->amount),
                'settled_amount' => Money::round((float) $payout->settled_amount),
                'remaining' => Money::round((float) $payout->amount - (float) $payout->settled_amount),
                'paid_at' => $payout->paid_at?->toDateString(),
            ])
            ->filter(fn (array $item): bool => $item['remaining'] > 0)
            ->values()
            ->all();
    }

    /**
     * Зачесть авансы FIFO на сумму не больше $limit и вернуть audit-снимок.
     *
     * @return array{total: float, lines: list<array{payout_id:int, applied:float, remaining_after:float}>}
     */
    public function settleAdvancesForBlockPayout(Teacher $teacher, float $limit, ?int $settledBy = null): array
    {
        $limit = Money::round(max(0.0, $limit));
        if ($limit <= 0) {
            return ['total' => 0.0, 'lines' => []];
        }

        $lines = [];
        $total = 0.0;

        DB::transaction(function () use ($teacher, $limit, $settledBy, &$lines, &$total): void {
            $remainingLimit = $limit;
            $advances = $teacher->payouts()
                ->unsettledAdvances()
                ->lockForUpdate()
                ->orderBy('paid_at')
                ->orderBy('id')
                ->get();

            foreach ($advances as $advance) {
                if ($remainingLimit <= 0) {
                    break;
                }

                $remainingAdvance = Money::round((float) $advance->amount - (float) $advance->settled_amount);
                if ($remainingAdvance <= 0) {
                    continue;
                }

                $applied = min($remainingAdvance, $remainingLimit);
                $newSettled = Money::round((float) $advance->settled_amount + $applied);
                $advance->settled_amount = $newSettled;
                $advance->settled_by = $settledBy;
                if ($newSettled >= Money::round((float) $advance->amount)) {
                    $advance->settled_at = now();
                }
                $advance->save();

                $remainingLimit = Money::round($remainingLimit - $applied);
                $total = Money::round($total + $applied);
                $lines[] = [
                    'payout_id' => (int) $advance->id,
                    'applied' => Money::round($applied),
                    'remaining_after' => Money::round((float) $advance->amount - $newSettled),
                ];
            }
        });

        return ['total' => $total, 'lines' => $lines];
    }

    /**
     * Сводка по всем преподавателям за выбранный месяц.
     *
     * @return array<int, array{
     *     teacher_id:int, name:string, courses_count:int,
     *     earned_period:float, earned_period_gross:float, returns_period:float,
     *     earned_all_time:float, paid_period:float, paid_all_time:float, balance:float
     * }>
     */
    public function summaryForAll(?string $periodMonth = null, array $opts = []): array
    {
        $periodMonth ??= now()->format('Y-m');
        $cacheKey = $periodMonth.'|'.json_encode($this->normalizeOptions($opts));
        if (isset($this->summaryCache[$cacheKey])) {
            return $this->summaryCache[$cacheKey];
        }

        [$start, $end] = $this->monthBounds($periodMonth);

        $teachers = Teacher::query()->with(['courses', 'coTaughtCourses'])->get();

        // Сумма всех выплат и выплат за период — батчем по всем преподам.
        $paidAllTime = TeacherPayout::query()
            ->selectRaw('teacher_id, SUM(CASE WHEN type = ? THEN COALESCE(settled_amount, 0) ELSE amount END) AS total', [TeacherPayout::TYPE_ADVANCE])
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        $paidPeriod = TeacherPayout::query()
            ->selectRaw('teacher_id, SUM(CASE WHEN type = ? THEN COALESCE(settled_amount, 0) ELSE amount END) AS total', [TeacherPayout::TYPE_ADVANCE])
            ->where(function ($q) use ($periodMonth, $start, $end) {
                $q->where('period_month', $periodMonth)
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('period_month')
                            // Суточные границы (issue #935, H1996): toDateString()
                            // терял выплаты последнего дня окна со временем в значении.
                            ->whereBetween('paid_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]);
                    });
            })
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        // Непогашенные авансы — отдельной величиной (деньги выданы, к ЗП не зачтены).
        $advancesOutstanding = TeacherPayout::query()
            ->unsettledAdvances()
            ->selectRaw('teacher_id, SUM(amount - COALESCE(settled_amount, 0)) AS total')
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        // Дата последней выплаты (любого типа: regular + advance) — батчем по
        // всем преподам, для колонки «Дней без выплаты».
        $lastPaidAt = TeacherPayout::query()
            ->selectRaw('teacher_id, MAX(paid_at) AS last_paid_at')
            ->groupBy('teacher_id')
            ->pluck('last_paid_at', 'teacher_id');

        $result = [];
        foreach ($teachers as $teacher) {
            $period = $this->periodTotals($teacher, $start, $end, $opts);
            $earnedAll = $this->totalForTeacher($teacher, null, null, $opts);
            $paidAll = (float) ($paidAllTime[$teacher->id] ?? 0);

            $lastPaidRaw = $lastPaidAt[$teacher->id] ?? null;
            $lastPaidCarbon = $lastPaidRaw ? Carbon::parse($lastPaidRaw)->startOfDay() : null;
            $daysSinceLastPayout = $lastPaidCarbon
                ? (int) $lastPaidCarbon->diffInDays(Carbon::now()->startOfDay())
                : null;

            // Прямые платежи на личный счёт преподавателя — зачёт по номиналу в
            // валюте выплаты. НЕ сводим с рублёвым balance (валюты разные): нетто
            // «к переводу» считается в валюте выплаты в момент выплаты (калькулятор),
            // как это делает бухгалтер. Здесь — отдельной информационной строкой.
            $directReceipts = $this->directReceiptsForTeacher($teacher, $start, $end);
            $directReceiptsAll = $this->directReceiptsForTeacher($teacher, null, null);

            $result[$teacher->id] = [
                'teacher_id' => (int) $teacher->id,
                'name' => (string) $teacher->name,
                'courses_count' => $teacher->allTaughtCourses()->count(),
                'earned_period' => $period['net'],          // чистое (валовое + возвраты)
                'earned_period_gross' => $period['gross'],   // только положительные начисления
                'returns_period' => $period['returns'],      // эффект возвратов на ЗП (<=0)
                'earned_all_time' => Money::round($earnedAll),
                'paid_period' => Money::round((float) ($paidPeriod[$teacher->id] ?? 0)),
                'paid_all_time' => Money::round($paidAll),
                'balance' => Money::round($earnedAll - $paidAll),
                'advances_outstanding' => Money::round((float) ($advancesOutstanding[$teacher->id] ?? 0)),
                // Зачёт прямых платежей (в валюте выплаты преподавателя), справочно.
                'direct_receipts_period' => $directReceipts['total'],
                'direct_receipts_all_time' => $directReceiptsAll['total'],
                'direct_receipts_currency' => $directReceipts['currency'],
                // Для колонки «Дней без выплаты» (любой тип выплаты, весь период).
                'last_paid_at' => $lastPaidCarbon?->toDateString(),
                'days_since_last_payout' => $daysSinceLastPayout,
            ];
        }

        return $this->summaryCache[$cacheKey] = $result;
    }

    /**
     * Сырые платежи преподавателя, признанные в окне, сгруппированные по курсу —
     * для drill-down в модалке «Разбивка» (из чего сложилось начисление).
     *
     * @return array<int, Collection<int, Payment>> course_id => payments
     */
    public function paymentsForTeacher(Teacher $teacher, $start = null, $end = null): array
    {
        $byCourse = [];
        foreach ($teacher->allTaughtCourses() as $course) {
            if (self::isTechnicalCourse($course) || $course->salaryTermsFor((int) $teacher->id) === null) {
                continue;
            }

            $blockNumbers = $this->blockNumbersFor($course->id);
            $blockMonths = $this->blockMonthsFor($course->id);

            $payments = $this->coursePayments($course->id)
                ->reject(fn (Payment $p) => in_array($p->tariff, self::NON_REVENUE_TARIFFS, true))
                ->filter(function (Payment $p) use ($blockNumbers, $blockMonths, $start, $end) {
                    $months = array_keys($this->recognizedShares($p, (float) $p->amount, $blockNumbers, $blockMonths));
                    foreach ($months as $m) {
                        if ($this->monthInWindow($m, $start, $end)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->sortByDesc('created_at')
                ->values();

            if ($payments->isNotEmpty()) {
                $byCourse[$course->id] = $payments;
            }
        }

        return $byCourse;
    }

    /**
     * Выручка одной группы за один блок — для калькулятора выплаты по блоку.
     * Сумма долей реальных платежей студентов группы, покрывающих этот блок:
     * блочный платёж (block_N) даёт всю сумму, full — долю amount/число_блоков
     * (как в accrual). $groupId=null → все студенты курса. Депозиты/пробные
     * входят (блоки пустые → разносятся на все блоки курса, как full).
     * Исключаем только возвраты (Расход), зеркала выплат и conditional.
     */
    public function blockGroupRevenue(int $courseId, int $blockNumber, ?int $groupId, array $opts = []): float
    {
        return $this->blockGroupRevenueDetail($courseId, $blockNumber, $groupId, $opts)['total'];
    }

    /**
     * То же, что blockGroupRevenue, но с детализацией: какие платежи и какой
     * долей вошли в сумму за блок (для превью калькулятора и аудита).
     *
     * @return array{total: float, lines: list<array<string, mixed>>}
     */
    public function blockGroupRevenueDetail(int $courseId, int $blockNumber, ?int $groupId, array $opts = []): array
    {
        $opts = $this->normalizeOptions($opts);
        $blockNumbers = $this->blockNumbersFor($courseId);
        $paid = [];
        if (! empty($opts['teacher_id']) && ($teacher = Teacher::find((int) $opts['teacher_id']))) {
            $paid = $this->paidShareKeys($teacher);
        }

        $query = Payment::query()
            ->with('user:id,name')
            ->where('course_id', $courseId)
            ->paid()
            ->real()
            ->schoolReceived() // прямые платежи преподавателю — не выручка блока
            ->whereNotIn('tariff', self::NON_REVENUE_TARIFFS)
            ->where('amount', '>', 0);

        if ($groupId !== null) {
            $query->whereIn('user_id', function ($q) use ($groupId) {
                $q->select('user_id')->from('group_user')->where('group_id', $groupId);
            });
        }

        $lines = [];
        $total = 0.0;
        foreach ($query->get() as $p) {
            $covered = $this->coveredBlockNumbers($p, $blockNumbers);
            if (empty($covered) || ! in_array($blockNumber, $covered, true)) {
                continue;
            }
            if (isset($paid[$courseId.':'.$blockNumber.':'.$p->id])) {
                continue;
            }
            $amount = $this->accrualAmount($p, $opts['gross_before_discount']);
            $share = $amount / count($covered);
            $total += $share;

            $lines[] = [
                'payment_id' => $p->id,
                'user_id' => $p->user_id,
                'user_name' => $p->user?->name ?? ('#'.$p->user_id),
                'tariff' => $p->tariff,
                'label' => $p->operationLabel(),
                'amount' => Money::round($amount),
                'covered_blocks' => count($covered),
                'share' => Money::round($share),
                'created_at' => $p->created_at?->format('d.m.Y'),
                'is_return' => false,
            ];
        }

        // Возвраты (Расход) по этому блоку вычитаются из базы: иначе преподавателю
        // платят % с уже возвращённых студенту денег (money-core H071 #5). Расход —
        // отдельный tariff с отрицательной суммой; он несёт start_block/end_block
        // возвращённой покупки, поэтому его доля раскладывается на те же блоки.
        if ($opts['subtract_returns']) {
            $returnQuery = Payment::query()
                ->with('user:id,name')
                ->where('course_id', $courseId)
                ->paid()
                ->real()
                ->where('tariff', self::EXPENSE_TARIFF);

            if ($groupId !== null) {
                $returnQuery->whereIn('user_id', function ($q) use ($groupId) {
                    $q->select('user_id')->from('group_user')->where('group_id', $groupId);
                });
            }

            foreach ($returnQuery->get() as $p) {
                $covered = $this->coveredBlockNumbers($p, $blockNumbers);
                if (empty($covered) || ! in_array($blockNumber, $covered, true)) {
                    continue;
                }
                $amount = (float) $p->amount; // отрицательная
                $share = $amount / count($covered);
                $total += $share;

                $lines[] = [
                    'payment_id' => $p->id,
                    'user_id' => $p->user_id,
                    'user_name' => $p->user?->name ?? ('#'.$p->user_id),
                    'tariff' => $p->tariff,
                    'label' => $p->operationLabel(),
                    'amount' => Money::round($amount),
                    'covered_blocks' => count($covered),
                    'share' => Money::round($share),
                    'created_at' => $p->created_at?->format('d.m.Y'),
                    'is_return' => true,
                ];
            }
        }

        // База блока не может быть отрицательной (возвраты сверх выручки блока).
        return ['total' => Money::round(max(0.0, $total)), 'lines' => $lines];
    }

    /**
     * Сколько льготников (бесплатный доступ) слушают блок: distinct студенты с
     * реальным оплаченным платежом нулевой суммы (100% промокод / выданный
     * бесплатный доступ), покрывающим этот блок. Параллель blockGroupRevenueDetail,
     * где amount>0 = платные. $groupId=null → все студенты курса. Возвраты/зеркала
     * выплат и conditional («под честное слово») исключены — это не льготники.
     */
    public function blockFreeStudentCount(int $courseId, int $blockNumber, ?int $groupId): int
    {
        $blockNumbers = $this->blockNumbersFor($courseId);

        $query = Payment::query()
            ->where('course_id', $courseId)
            ->paid()
            ->real()
            ->schoolReceived() // симметрично blockGroupRevenueDetail
            ->whereNotIn('tariff', self::NON_REVENUE_TARIFFS)
            ->where('amount', '=', 0);

        if ($groupId !== null) {
            $query->whereIn('user_id', function ($q) use ($groupId) {
                $q->select('user_id')->from('group_user')->where('group_id', $groupId);
            });
        }

        $userIds = [];
        foreach ($query->get() as $p) {
            $covered = $this->coveredBlockNumbers($p, $blockNumbers);
            if (! empty($covered) && in_array($blockNumber, $covered, true)) {
                $userIds[$p->user_id] = true;
            }
        }

        return count($userIds);
    }

    /**
     * Множество уже выплаченных долей платежей в виде set ключей
     * "{course_id}:{block_number}:{payment_id}" => true. Собирается из аудита всех
     * выплат преподавателя: и из обычного состава блока (breakdown.payments под
     * breakdown.course_id/block_number), и из ранее добавленных поздних оплат
     * (breakdown.prior_blocks_paid). Используется, чтобы не предлагать и не
     * задвоить уже оплаченное.
     *
     * @return array<string, true>
     */
    public function paidShareKeys(Teacher $teacher): array
    {
        $keys = [];

        foreach ($teacher->payouts()->whereNotNull('breakdown')->get() as $payout) {
            $b = $payout->breakdown ?? [];
            $courseId = $b['course_id'] ?? null;
            $block = $b['block_number'] ?? null;

            if ($courseId !== null && $block !== null) {
                foreach ($b['payments'] ?? [] as $line) {
                    if (isset($line['payment_id'])) {
                        $keys[$courseId.':'.$block.':'.$line['payment_id']] = true;
                    }
                }
            }

            foreach ($b['prior_blocks_paid'] ?? [] as $item) {
                if (isset($item['course_id'], $item['block_number'], $item['payment_id'])) {
                    $keys[$item['course_id'].':'.$item['block_number'].':'.$item['payment_id']] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Доли платежей с прошлых блоков всех percent-курсов преподавателя, которые
     * ещё НЕ вошли ни в одну выплату — кандидаты на добавление в текущий расчёт.
     * Исключаем: уже выплаченное (paidShareKeys), текущую пару (course, block) —
     * она уже в base_revenue, и блоки, ещё не начавшиеся (starts_at в будущем).
     *
     * @return list<array{key:string,course_id:int,course_title:string,block_number:int,payment_id:int,user_id:int,user_name:string,amount:float,share:float,percent:float,teacher_amount:float}>
     */
    public function availablePriorBlockPayments(Teacher $teacher, ?int $excludeCourseId, ?int $excludeBlockNumber, float $fallbackPercent = 0.0): array
    {
        $paid = $this->paidShareKeys($teacher);
        $today = Carbon::today()->toDateString();
        $items = [];

        foreach ($teacher->allTaughtCourses() as $course) {
            $terms = $course->salaryTermsFor((int) $teacher->id);
            if (self::isTechnicalCourse($course) || $terms === null
                || ! SalaryScheme::isPercentType($terms['type'])) {
                continue;
            }

            // У курса процент может быть не задан (salary_value=0) — тогда берём
            // процент из текущего расчёта (поле калькулятора). Для со-препода —
            // его процент из pivot.
            $percent = (float) $terms['value'] ?: $fallbackPercent;
            $blockNumbers = $this->blockNumbersFor($course->id);
            // [number => 'Y-m-d'] для датированных блоков — чтобы отсечь ещё не начавшиеся.
            $blockStarts = $this->blockStartDatesFor($course->id);

            foreach ($this->coursePayments($course->id) as $p) {
                if (! $this->isCourseRevenuePayment($p)) {
                    continue;
                }

                $covered = $this->coveredBlockNumbers($p, $blockNumbers);
                if (empty($covered)) {
                    continue;
                }
                $share = Money::round($this->accrualAmount($p, false) / count($covered));

                foreach ($covered as $block) {
                    // Текущая пара (курс, блок) уже учтена в base_revenue.
                    if ($excludeCourseId === $course->id && $excludeBlockNumber === $block) {
                        continue;
                    }
                    // Уже выплачено ранее.
                    if (isset($paid[$course->id.':'.$block.':'.$p->id])) {
                        continue;
                    }
                    // Блок ещё не начался (датированный будущий) — не «прошлый».
                    $start = $blockStarts[$block] ?? null;
                    if ($start !== null && $start > $today) {
                        continue;
                    }

                    $items[] = [
                        'key' => $course->id.':'.$block.':'.$p->id,
                        'course_id' => $course->id,
                        'course_title' => (string) $course->title,
                        'block_number' => $block,
                        'payment_id' => $p->id,
                        'user_id' => (int) $p->user_id,
                        'user_name' => $p->user?->name ?? ('#'.$p->user_id),
                        'amount' => Money::round((float) $p->amount),
                        'share' => $share,
                        'percent' => $percent,
                        'teacher_amount' => Money::round($share * $percent / 100),
                    ];
                }
            }
        }

        usort($items, fn ($a, $b) => [$a['course_title'], $a['block_number'], $a['user_name']]
            <=> [$b['course_title'], $b['block_number'], $b['user_name']]);

        return $items;
    }

    /**
     * Итог выплаты по формуле калькулятора:
     *   (база × коэф%) × процент_препода% + допзанятия × коэф% + доплата.
     * Допзанятия идут через коэффициент, но БЕЗ процента преподавателя.
     * Доплата — фиксированная добавка (когда расчёт не дотягивает до минимума),
     * прибавляется к итогу как есть (без коэффициента и процента).
     * Удержание — фиксированный вычет из итога (штраф/аванс/корректировка),
     * вычитается по модулю как есть (без коэффициента и процента).
     * Поздние оплаты прошлых блоков идут ЧЕРЕЗ коэффициент текущего блока, как и
     * основная выручка: $priorBlocksTotal — это сумма долей с уже применённым
     * процентом своего курса (teacher_amount = доля × процент), а коэффициент
     * домножается здесь, в единой точке. Т.е. поздняя оплата эквивалентна добавке
     * к базе: (база + поздняя) × коэф × процент.
     * Прямой зачёт ($directOffset) — номинал прямых платежей на личный счёт
     * преподавателя, вычитается из итога КАК ЕСТЬ (без коэффициента и процента),
     * отдельно от штрафного «Удержания», чтобы в аудите не смешивать зачёт с
     * штрафом. Ожидается в той же валюте, что и остальной итог.
     */
    public static function blockPayoutTotal(float $base, float $coef, float $teacherPct, float $extrasTotal, float $surcharge = 0.0, float $deduction = 0.0, float $priorBlocksTotal = 0.0, float $directOffset = 0.0): float
    {
        return Money::round(($base * $coef / 100) * ($teacherPct / 100) + $extrasTotal * ($coef / 100) + $surcharge - abs($deduction) + $priorBlocksTotal * ($coef / 100) - abs($directOffset));
    }

    /**
     * Помесячная ВЫРУЧКА курса (без условий препода) — кэшируется по курсу.
     * Зависит только от платежей курса, поэтому переиспользуется для всех
     * преподавателей курса (у каждого свои условия начисляются поверх).
     *
     * @param  array{gross_before_discount: bool, subtract_returns: bool}  $opts
     * @return array{revenue: array<string,float>, returnsByMonth: array<string,float>, revenueEvents: list<array{month:string,amount:float,created_at:?Carbon,user_id:?int}>, returnEvents: list<array{month:string,amount:float,created_at:?Carbon,user_id:?int}>, studentEarliest: array<int,string>, blockNumbers: list<int>, blockMonths: array<int,string>, totalBlocks: int}
     */
    private function courseRevenueData(Course $course, array $opts): array
    {
        $key = $course->id.'|'.json_encode($opts);

        return $this->courseRevenueCache[$key] ??= $this->computeCourseRevenue($course, $opts);
    }

    /**
     * @param  array{gross_before_discount: bool, subtract_returns: bool}  $opts
     * @return array{revenue: array<string,float>, returnsByMonth: array<string,float>, revenueEvents: list<array{month:string,amount:float,created_at:?Carbon,user_id:?int}>, returnEvents: list<array{month:string,amount:float,created_at:?Carbon,user_id:?int}>, studentEarliest: array<int,string>, blockNumbers: list<int>, blockMonths: array<int,string>, totalBlocks: int}
     */
    private function computeCourseRevenue(Course $course, array $opts): array
    {
        $blockNumbers = $this->blockNumbersFor($course->id);
        $blockMonths = $this->blockMonthsFor($course->id);
        $totalBlocks = max(1, count($blockNumbers));

        $payments = $this->coursePayments($course->id);

        // Прямые платежи на личный счёт преподавателя НЕ образуют выручку курса:
        // деньги не прошли через кассу школы, а зачитываются в гонорар по номиналу
        // (directReceiptsForTeacher). Иначе — двойной счёт: препод и держит сумму,
        // и получил бы свой процент сверху. См. docs/direct-teacher-receipts.md.
        $real = $payments->filter(fn (Payment $p) => $this->isCourseRevenuePayment($p));
        $returns = $payments->filter(fn (Payment $p) => $this->isReturnPayment($p));

        // Положительная выручка по месяцам (без возвратов) + ранний месяц студента.
        $revenue = [];
        $returnsByMonth = [];
        $revenueEvents = [];
        $returnEvents = [];
        $studentEarliest = [];
        foreach ($real as $p) {
            $amount = $this->accrualAmount($p, $opts['gross_before_discount']);
            $shares = $this->recognizedShares($p, $amount, $blockNumbers, $blockMonths);
            foreach ($shares as $m => $amt) {
                $revenue[$m] = ($revenue[$m] ?? 0.0) + $amt;
                $revenueEvents[] = [
                    'month' => $m,
                    'amount' => (float) $amt,
                    'created_at' => $p->created_at,
                    'user_id' => $p->user_id ? (int) $p->user_id : null,
                ];
            }
            if ($p->user_id && ! empty($shares)) {
                $earliest = min(array_keys($shares));
                if (! isset($studentEarliest[$p->user_id]) || $earliest < $studentEarliest[$p->user_id]) {
                    $studentEarliest[$p->user_id] = $earliest;
                }
            }
        }

        // Возвраты/расходы по месяцам (A2). Признаются по дате операции/override.
        if ($opts['subtract_returns']) {
            foreach ($returns as $p) {
                $month = $p->salary_recognition_month ?: $p->created_at?->format('Y-m');
                if ($month) {
                    $returnsByMonth[$month] = ($returnsByMonth[$month] ?? 0.0) + (float) $p->amount;
                    $returnEvents[] = [
                        'month' => $month,
                        'amount' => (float) $p->amount,
                        'created_at' => $p->created_at,
                        'user_id' => $p->user_id ? (int) $p->user_id : null,
                    ];
                }
            }
        }

        return [
            'revenue' => $revenue,
            'returnsByMonth' => $returnsByMonth,
            'revenueEvents' => $revenueEvents,
            'returnEvents' => $returnEvents,
            'studentEarliest' => $studentEarliest,
            'blockNumbers' => $blockNumbers,
            'blockMonths' => $blockMonths,
            'totalBlocks' => $totalBlocks,
        ];
    }

    /**
     * Помесячное начисление пары (курс, препод) по ЭФФЕКТИВНЫМ условиям препода
     * ($type/$value из Course::salaryTermsFor). Ролл-форвард по закрытым месяцам
     * ИМЕННО этого преподавателя. Кэш по course|teacher|opts.
     *
     * @param  array{gross_before_discount: bool, subtract_returns: bool}  $opts
     * @return array{accrued: array<string,float>, accrued_gross: array<string,float>, accrued_returns: array<string,float>, revenue: array<string,float>, returns: array<string,float>, students: array<int,string>}
     */
    private function teacherCourseAccrual(Course $course, int $teacherId, ?string $type, float $value, array $opts): array
    {
        $key = $course->id.'|'.$teacherId.'|'.json_encode($opts);

        return $this->teacherCourseAccrualCache[$key] ??= $this->computeTeacherCourseAccrual($course, $teacherId, $type, $value, $opts);
    }

    /**
     * @param  array{gross_before_discount: bool, subtract_returns: bool}  $opts
     * @return array{accrued: array<string,float>, accrued_gross: array<string,float>, accrued_returns: array<string,float>, revenue: array<string,float>, returns: array<string,float>, students: array<int,string>}
     */
    private function computeTeacherCourseAccrual(Course $course, int $teacherId, ?string $type, float $value, array $opts): array
    {
        $rev = $this->courseRevenueData($course, $opts);
        $revenue = $rev['revenue'];
        $returnsByMonth = $rev['returnsByMonth'];
        $revenueEvents = $rev['revenueEvents'];
        $returnEvents = $rev['returnEvents'];
        $studentEarliest = $rev['studentEarliest'];
        $blockNumbers = $rev['blockNumbers'];
        $blockMonths = $rev['blockMonths'];
        $totalBlocks = $rev['totalBlocks'];

        $type = (string) $type;
        $accruedGross = [];
        $accruedReturns = [];

        $closed = $this->closedPeriodsFor($teacherId);

        switch ($type) {
            case 'percent':
            case 'percent_per_block':
                $revenue = [];
                // Ранний месяц студента считаем в этом же проходе (роллфорвард $m
                // уже посчитан на событие) — без второго прохода по revenueEvents.
                $studentEarliest = [];
                foreach ($revenueEvents as $event) {
                    $m = $this->rollForwardEventMonth($event['month'], $closed);
                    $amt = $event['amount'];
                    $revenue[$m] = ($revenue[$m] ?? 0.0) + $amt;
                    $accruedGross[$m] = ($accruedGross[$m] ?? 0.0) + $amt * ($value / 100);

                    $uid = $event['user_id'];
                    if ($uid !== null && (! isset($studentEarliest[$uid]) || $m < $studentEarliest[$uid])) {
                        $studentEarliest[$uid] = $m;
                    }
                }
                // На ЗП возвраты влияют только в процентных схемах.
                $returnsByMonth = [];
                foreach ($returnEvents as $event) {
                    $m = $this->rollForwardEventMonth($event['month'], $closed);
                    $amt = $event['amount'];
                    $returnsByMonth[$m] = ($returnsByMonth[$m] ?? 0.0) + $amt;
                    $accruedReturns[$m] = ($accruedReturns[$m] ?? 0.0) + $amt * ($value / 100);
                }
                break;

            case 'fix_per_block':
                $real = $this->realPaymentsFor($course->id);
                $fallback = $this->fallbackMonth($blockMonths, $real);
                foreach ($blockNumbers as $n) {
                    $m = $blockMonths[$n] ?? $fallback;
                    if ($m !== null) {
                        $accruedGross[$m] = ($accruedGross[$m] ?? 0.0) + $value;
                    }
                }
                break;

            case 'fix_total':
                $real = $this->realPaymentsFor($course->id);
                if ($real->isNotEmpty()) {
                    $fallback = $this->fallbackMonth($blockMonths, $real);
                    $per = $value / $totalBlocks;
                    foreach ($blockNumbers as $n) {
                        $m = $blockMonths[$n] ?? $fallback;
                        if ($m !== null) {
                            $accruedGross[$m] = ($accruedGross[$m] ?? 0.0) + $per;
                        }
                    }
                }
                break;

            case 'fix_per_student':
                foreach ($studentEarliest as $m) {
                    $accruedGross[$m] = ($accruedGross[$m] ?? 0.0) + $value;
                }
                break;
        }

        // Чистое начисление = валовое + эффект возвратов.
        $accrued = $accruedGross;
        foreach ($accruedReturns as $m => $amt) {
            $accrued[$m] = ($accrued[$m] ?? 0.0) + $amt;
        }

        // Ролл-форвард: всё, что попало в закрытый месяц ЭТОГО преподавателя,
        // переносим в ближайший открытый (нельзя начислять в уже рассчитанный).
        $closedMonths = array_keys($closed);
        if (! empty($closedMonths) && ! SalaryScheme::isPercentType($type)) {
            $accrued = $this->remapMonths($accrued, $closedMonths);
            $accruedGross = $this->remapMonths($accruedGross, $closedMonths);
            $accruedReturns = $this->remapMonths($accruedReturns, $closedMonths);
            $revenue = $this->remapMonths($revenue, $closedMonths);
            $returnsByMonth = $this->remapMonths($returnsByMonth, $closedMonths);
            $studentEarliest = array_map(fn (string $m) => $this->rollForwardMonth($m, $closedMonths), $studentEarliest);
        }

        return [
            'accrued' => $accrued,
            'accrued_gross' => $accruedGross,
            'accrued_returns' => $accruedReturns,
            'revenue' => $revenue,
            'returns' => $returnsByMonth,
            'students' => $studentEarliest,
        ];
    }

    /**
     * Реальные платежи курса (без не-выручки и нулей) — для фоллбэк-месяца
     * фикс-схем. coursePayments кэшируется, фильтр дешёвый.
     *
     * @return Collection<int, Payment>
     */
    private function realPaymentsFor(int $courseId): Collection
    {
        return $this->coursePayments($courseId)->reject(
            fn (Payment $p) => in_array($p->tariff, self::NON_REVENUE_TARIFFS, true) || (float) $p->amount <= 0
        );
    }

    /** Возврат/расход (tariff='Расход', отрицательная сумма) — вычитается из базы. */
    private function isReturnPayment(Payment $payment): bool
    {
        return $payment->tariff === self::EXPENSE_TARIFF;
    }

    /**
     * Платёж образует ВЫРУЧКУ КУРСА: не возврат/зеркало выплаты, положительная
     * сумма и получен кассой школы. Прямые платежи на личный счёт преподавателя
     * (RECEIVED_TEACHER) выручкой не считаются — они зачитываются в гонорар по
     * номиналу (directReceiptsForTeacher), иначе двойной счёт. Единый предикат
     * для computeCourseRevenue и availablePriorBlockPayments.
     */
    private function isCourseRevenuePayment(Payment $payment): bool
    {
        return ! in_array($payment->tariff, self::NON_REVENUE_TARIFFS, true)
            && (float) $payment->amount > 0
            && $payment->received_account !== Payment::RECEIVED_TEACHER;
    }

    /**
     * Дата начала каждого датированного блока курса: [block_number => 'Y-m-d'].
     * Кэш request-scoped — как blockMonthsFor, чтобы не бить CourseBlock в цикле
     * по курсам преподавателя (availablePriorBlockPayments).
     *
     * @return array<int, string>
     */
    private function blockStartDatesFor(int $courseId): array
    {
        if (isset($this->blockStartDatesCache[$courseId])) {
            return $this->blockStartDatesCache[$courseId];
        }

        return $this->blockStartDatesCache[$courseId] = CourseBlock::query()
            ->where('course_id', $courseId)
            ->whereNotNull('starts_at')
            ->get(['number', 'starts_at'])
            ->mapWithKeys(fn (CourseBlock $cb) => [(int) $cb->number => $cb->starts_at->toDateString()])
            ->all();
    }

    /**
     * Закрытые месяцы преподавателя (ролл-форвард). Предзагрузка всех периодов
     * одним запросом, request-scoped.
     *
     * @return list<string> месяцы 'Y-m'
     */
    private function closedMonthsFor(int $teacherId): array
    {
        if ($this->closedMonthsCache === null) {
            $this->closedMonthsCache = [];
            foreach (SalaryClosedPeriod::query()->get(['teacher_id', 'period_month']) as $row) {
                $this->closedMonthsCache[(int) $row->teacher_id][] = (string) $row->period_month;
            }
        }

        return $this->closedMonthsCache[$teacherId] ?? [];
    }

    /**
     * @return array<string, Carbon> period_month => closed_at
     */
    private function closedPeriodsFor(int $teacherId): array
    {
        if ($this->closedPeriodCache === null) {
            $this->closedPeriodCache = [];
            foreach (SalaryClosedPeriod::query()->get(['teacher_id', 'period_month', 'closed_at']) as $row) {
                $this->closedPeriodCache[(int) $row->teacher_id][(string) $row->period_month] = Carbon::parse($row->closed_at);
            }
        }

        return $this->closedPeriodCache[$teacherId] ?? [];
    }

    /**
     * Закрытый месяц (этого преподавателя) переносит признание в ближайший
     * открытый — независимо от того, когда создан платёж. Закрытие периода
     * означает «в этот месяц больше ничего не начисляем», а не только для
     * задним числом добавленных событий.
     *
     * @param  array<string, Carbon>  $closed
     */
    private function rollForwardEventMonth(string $month, array $closed): string
    {
        return $this->rollForwardMonth($month, array_keys($closed));
    }

    /**
     * Перенос помесячной карты: ключи-месяцы, попавшие в закрытые, сдвигаются
     * вперёд до ближайшего открытого; при коллизии суммы складываются.
     *
     * @param  array<string, float>  $byMonth
     * @param  list<string>  $closed
     * @return array<string, float>
     */
    private function remapMonths(array $byMonth, array $closed): array
    {
        $out = [];
        foreach ($byMonth as $month => $amount) {
            $target = $this->rollForwardMonth($month, $closed);
            $out[$target] = ($out[$target] ?? 0.0) + $amount;
        }

        return $out;
    }

    /**
     * Ближайший открытый месяц начиная с $month включительно (пока месяц закрыт —
     * +1 месяц). Конечное число закрытых → цикл завершается.
     *
     * @param  list<string>  $closed
     */
    private function rollForwardMonth(string $month, array $closed): string
    {
        $guard = 0;
        while (in_array($month, $closed, true) && $guard++ < 600) {
            $month = $this->nextMonth($month);
        }

        return $month;
    }

    private function nextMonth(string $month): string
    {
        return $this->monthStart($month)->addMonth()->format('Y-m');
    }

    /**
     * Раскладка суммы платежа по месяцам признания: override → один месяц;
     * иначе доля за каждый оплаченный блок в месяце этого блока (fallback —
     * месяц created_at для блока без даты / платежа без блоков).
     *
     * @param  list<int>  $blockNumbers
     * @param  array<int, string>  $blockMonths
     * @return array<string, float>
     */
    private function recognizedShares(Payment $payment, float $amount, array $blockNumbers, array $blockMonths): array
    {
        return $this->recognizedAttribution($payment, $amount, $blockNumbers, $blockMonths)['shares'];
    }

    /**
     * Та же раскладка, но С НАЗВАННЫМ МЕХАНИЗМОМ атрибуции (H3951): column /
     * blocks / created / blocks_degenerate. Публична ради аудита
     * (recognition:attribution-audit) — отчёт по ЗП обязан уметь сказать по
     * каждой строке, признана она колонкой salary_recognition_month или
     * эвристикой, и какой именно.
     *
     * @param  list<int>  $blockNumbers
     * @param  array<int, string>  $blockMonths
     * @return array{shares: array<string, float>, mechanism: string, degenerate: bool}
     */
    public function recognizedAttribution(
        Payment $payment,
        float $amount,
        array $blockNumbers,
        array $blockMonths,
        ?bool $degenerateGuard = null,
    ): array {
        $createdMonth = $payment->created_at?->format('Y-m') ?? now()->format('Y-m');
        $courseId = $payment->course_id ? (int) $payment->course_id : null;

        return BlockMonthRecognition::attribute(
            $amount,
            $payment->salary_recognition_month,
            $this->coveredBlockNumbers($payment, $blockNumbers),
            $blockMonths,
            $courseId ? $this->blockStartDatesFor($courseId) : [],
            $createdMonth,
            $degenerateGuard ?? BlockMonthRecognition::degenerateGuardEnabled(),
        );
    }

    /**
     * Номера блоков, которые покрывает платёж. full / (null,null) → все блоки
     * курса. Делегирует канонический алгоритм BlockMonthRecognition (тот же, что в
     * признании выручки) — тонкая обёртка, чтобы 5 вызывающих мест внутри сервиса
     * не переписывать.
     *
     * @param  list<int>  $blockNumbers
     * @return list<int>
     */
    private function coveredBlockNumbers(Payment $payment, array $blockNumbers): array
    {
        return BlockMonthRecognition::coveredBlockNumbers($payment->start_block, $payment->end_block, $blockNumbers);
    }

    /**
     * Сумма платежа для начисления: нетто (фактически уплачено) по умолчанию,
     * либо брутто — цена до скидки, если включён флаг (A3).
     */
    private function accrualAmount(Payment $payment, bool $grossBeforeDiscount): float
    {
        $amount = (float) $payment->amount;
        if (! $grossBeforeDiscount) {
            return $amount;
        }

        $pct = (float) $payment->discount_percent;
        if ($pct > 0 && $pct < 100) {
            return $amount / (1 - $pct / 100);
        }

        $fixed = (float) $payment->discount_amount;
        if ($fixed > 0) {
            return $amount + $fixed;
        }

        return $amount;
    }

    /**
     * Все paid/non-conditional платежи курса (за всё время) — кэшируются.
     *
     * @return Collection<int, Payment>
     */
    private function coursePayments(int $courseId): Collection
    {
        return $this->coursePaymentsCache[$courseId] ??= Payment::query()
            ->where('course_id', $courseId)
            ->with('user:id,name')
            ->paid()
            ->real()
            ->get();
    }

    /**
     * Отсортированные номера блоков курса (из CourseBlock; fallback — 1..maxTariffBlock).
     *
     * @return list<int>
     */
    private function blockNumbersFor(int $courseId): array
    {
        if (isset($this->blockNumbersCache[$courseId])) {
            return $this->blockNumbersCache[$courseId];
        }

        $nums = CourseBlock::query()
            ->where('course_id', $courseId)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($n) => (int) $n)
            ->all();

        if (empty($nums)) {
            $nums = range(1, $this->totalBlocksFor($courseId));
        }

        return $this->blockNumbersCache[$courseId] = $nums;
    }

    /**
     * Месяц начала каждого датированного блока курса: [block_number => 'Y-m'].
     *
     * @return array<int, string>
     */
    private function blockMonthsFor(int $courseId): array
    {
        if (isset($this->blockMonthsCache[$courseId])) {
            return $this->blockMonthsCache[$courseId];
        }

        $map = CourseBlock::query()
            ->where('course_id', $courseId)
            ->whereNotNull('starts_at')
            ->get(['number', 'starts_at'])
            ->mapWithKeys(fn (CourseBlock $b) => [(int) $b->number => $b->starts_at->format('Y-m')])
            ->all();

        return $this->blockMonthsCache[$courseId] = $map;
    }

    /**
     * Месяц-фоллбэк для фиксированных схем по блокам без даты: ранний
     * датированный блок, иначе ранний месяц реальной оплаты.
     *
     * @param  array<int, string>  $blockMonths
     * @param  Collection<int, Payment>  $real
     */
    private function fallbackMonth(array $blockMonths, Collection $real): ?string
    {
        if (! empty($blockMonths)) {
            return min(array_values($blockMonths));
        }

        $min = null;
        foreach ($real as $p) {
            $m = $p->created_at?->format('Y-m');
            if ($m !== null && ($min === null || $m < $min)) {
                $min = $m;
            }
        }

        return $min;
    }

    /**
     * Максимальный номер блока среди тарифов курса (>=1, чтобы не делить на 0).
     */
    private function totalBlocksFor(int $courseId): int
    {
        if (isset($this->maxBlockCache[$courseId])) {
            return $this->maxBlockCache[$courseId];
        }

        $max = (int) Tariff::query()
            ->where('course_id', $courseId)
            ->whereNotNull('block_number')
            ->where('block_number', '>', 0)
            ->max('block_number');

        return $this->maxBlockCache[$courseId] = max(1, $max);
    }

    /**
     * Сумма помесячной карты по окну [$start; $end] (null,null = всё время).
     *
     * @param  array<string, float>  $byMonth
     */
    private function windowSum(array $byMonth, $start, $end): float
    {
        $sum = 0.0;
        foreach ($byMonth as $month => $amount) {
            if ($this->monthInWindow($month, $start, $end)) {
                $sum += $amount;
            }
        }

        return $sum;
    }

    /**
     * Уникальные студенты, чей ранний месяц признания попал в окно.
     *
     * @param  array<int, string>  $studentEarliest
     */
    private function studentsInWindow(array $studentEarliest, $start, $end): int
    {
        $count = 0;
        foreach ($studentEarliest as $month) {
            if ($this->monthInWindow($month, $start, $end)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Пересекается ли месяц 'Y-m' с окном [$start; $end] (null,null = да).
     */
    private function monthInWindow(string $month, $start, $end): bool
    {
        if (! $start || ! $end) {
            return true;
        }

        $mStart = $this->monthStart($month);
        $mEnd = $mStart->copy()->endOfMonth();

        return $mStart->lte(Carbon::parse($end)->endOfDay())
            && $mEnd->gte(Carbon::parse($start)->startOfDay());
    }

    private function monthStart(string $periodMonth): Carbon
    {
        return Carbon::parse($periodMonth.'-01')->startOfMonth();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthBounds(string $periodMonth): array
    {
        $start = $this->monthStart($periodMonth);

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * @param  array{gross_before_discount?: bool, subtract_returns?: bool, teacher_id?: int}  $opts
     * @return array{gross_before_discount: bool, subtract_returns: bool, teacher_id?: int}
     */
    private function normalizeOptions(array $opts): array
    {
        $normalized = [
            'gross_before_discount' => (bool) ($opts['gross_before_discount'] ?? false),
            'subtract_returns' => (bool) ($opts['subtract_returns'] ?? true),
        ];

        if (isset($opts['teacher_id'])) {
            $normalized['teacher_id'] = (int) $opts['teacher_id'];
        }

        return $normalized;
    }
}
