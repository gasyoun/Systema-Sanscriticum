<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\SalaryClosedPeriod;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     */
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    private const EXPENSE_TARIFF = 'Расход';

    /** @var array<int, int>  course_id => max block_number (>=1) */
    private array $maxBlockCache = [];

    /** @var array<int, list<int>>  course_id => отсортированные номера блоков */
    private array $blockNumbersCache = [];

    /** @var array<int, array<int, string>>  course_id => [block_number => 'Y-m'] (только с датой) */
    private array $blockMonthsCache = [];

    /** @var array<int, Collection<int, Payment>>  course_id => все paid/non-conditional платежи */
    private array $coursePaymentsCache = [];

    /** @var array<string, array<string, mixed>>  "course_id|opts" => помесячное начисление курса */
    private array $courseAccrualCache = [];

    /** @var array<string, array<int, mixed>>  кэш сводки по ключу периода */
    private array $summaryCache = [];

    /** @var array<int, list<string>>|null  teacher_id => закрытые месяцы 'Y-m' */
    private ?array $closedMonthsCache = null;

    /**
     * Человекочитаемые подписи схем оплаты курса.
     *
     * @return array<string, string>
     */
    public static function salaryTypeLabels(): array
    {
        return [
            'percent' => '% от выручки',
            'fix_per_student' => 'Фикс за студента',
            'fix_total' => 'Фикс за курс',
            'percent_per_block' => '% за блок',
            'fix_per_block' => 'Фикс за блок',
        ];
    }

    public static function salaryTypeLabel(?string $type): string
    {
        return self::salaryTypeLabels()[$type] ?? ($type ?: '—');
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

        foreach ($teacher->courses as $course) {
            if (self::isTechnicalCourse($course)) {
                continue;
            }

            $data = $this->courseAccrual($course, $opts);

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
                'salary_type' => $course->salary_type,
                'salary_value' => (float) $course->salary_value,
                'students' => $students,
                'revenue' => round($revenue, 2),
                'returns' => round($returns, 2),
                'accrued' => round($accrued, 2),
            ];

            $total += $accrued;
        }

        return ['rows' => $rows, 'total' => round($total, 2)];
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

        foreach ($teacher->courses as $course) {
            if (self::isTechnicalCourse($course)) {
                continue;
            }
            $data = $this->courseAccrual($course, $opts);
            $net += $this->windowSum($data['accrued'], $start, $end);
            $gross += $this->windowSum($data['accrued_gross'], $start, $end);
            $returns += $this->windowSum($data['accrued_returns'], $start, $end);
        }

        return ['net' => round($net, 2), 'gross' => round($gross, 2), 'returns' => round($returns, 2)];
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
        foreach ($teacher->courses as $course) {
            if (self::isTechnicalCourse($course)) {
                continue;
            }
            $value = (float) $course->salary_value;
            $isPercent = in_array($course->salary_type, ['percent', 'percent_per_block'], true);

            foreach ($this->coursePayments($course->id) as $p) {
                if ($p->tariff !== self::EXPENSE_TARIFF) {
                    continue;
                }
                $month = $p->salary_recognition_month ?: $p->created_at?->format('Y-m');
                if (! $month || ! $this->monthInWindow($month, $start, $end)) {
                    continue;
                }
                $amount = (float) $p->amount;
                $lines[] = [
                    'course_title' => (string) $course->title,
                    'amount' => round($amount, 2),
                    'effect' => round($isPercent ? $amount * ($value / 100) : 0.0, 2),
                    'date' => $p->created_at?->format('d.m.Y'),
                    'note' => $p->transaction_id,
                ];
            }
        }

        return $lines;
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

        $teachers = Teacher::query()->with('courses')->get();

        // «Выплачено» = обычные выплаты + уже зачтённые авансы. Непогашенный аванс
        // (type=advance, settled_at IS NULL) деньги выданы, но к ЗП ещё не зачтён —
        // в «выплачено»/balance НЕ идёт, считается отдельно (advancesOutstanding).
        $excludeUnsettledAdvances = function ($q) {
            $q->where('type', '!=', TeacherPayout::TYPE_ADVANCE)
                ->orWhereNotNull('settled_at');
        };

        // Сумма всех выплат и выплат за период — батчем по всем преподам.
        $paidAllTime = TeacherPayout::query()
            ->where($excludeUnsettledAdvances)
            ->selectRaw('teacher_id, SUM(amount) AS total')
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        $paidPeriod = TeacherPayout::query()
            ->where($excludeUnsettledAdvances)
            ->selectRaw('teacher_id, SUM(amount) AS total')
            ->where(function ($q) use ($periodMonth, $start, $end) {
                $q->where('period_month', $periodMonth)
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('period_month')
                            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()]);
                    });
            })
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        // Непогашенные авансы — отдельной величиной (деньги выданы, к ЗП не зачтены).
        $advancesOutstanding = TeacherPayout::query()
            ->unsettledAdvances()
            ->selectRaw('teacher_id, SUM(amount) AS total')
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        $result = [];
        foreach ($teachers as $teacher) {
            $period = $this->periodTotals($teacher, $start, $end, $opts);
            $earnedAll = $this->totalForTeacher($teacher, null, null, $opts);
            $paidAll = (float) ($paidAllTime[$teacher->id] ?? 0);

            $result[$teacher->id] = [
                'teacher_id' => (int) $teacher->id,
                'name' => (string) $teacher->name,
                'courses_count' => $teacher->courses->count(),
                'earned_period' => $period['net'],          // чистое (валовое + возвраты)
                'earned_period_gross' => $period['gross'],   // только положительные начисления
                'returns_period' => $period['returns'],      // эффект возвратов на ЗП (<=0)
                'earned_all_time' => round($earnedAll, 2),
                'paid_period' => round((float) ($paidPeriod[$teacher->id] ?? 0), 2),
                'paid_all_time' => round($paidAll, 2),
                'balance' => round($earnedAll - $paidAll, 2),
                'advances_outstanding' => round((float) ($advancesOutstanding[$teacher->id] ?? 0), 2),
            ];
        }

        return $this->summaryCache[$cacheKey] = $result;
    }

    /**
     * Сырые платежи преподавателя, признанные в окне, сгруппированные по курсу —
     * для drill-down в модалке «Разбивка» (из чего сложилось начисление).
     *
     * @return array<int, \Illuminate\Support\Collection<int, Payment>> course_id => payments
     */
    public function paymentsForTeacher(Teacher $teacher, $start = null, $end = null): array
    {
        $byCourse = [];
        foreach ($teacher->courses as $course) {
            if (self::isTechnicalCourse($course)) {
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

        $query = Payment::query()
            ->with('user:id,name')
            ->where('course_id', $courseId)
            ->paid()
            ->real()
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
            $amount = $this->accrualAmount($p, $opts['gross_before_discount']);
            $share = $amount / count($covered);
            $total += $share;

            $lines[] = [
                'payment_id' => $p->id,
                'user_id' => $p->user_id,
                'user_name' => $p->user?->name ?? ('#'.$p->user_id),
                'tariff' => $p->tariff,
                'label' => $p->operationLabel(),
                'amount' => round($amount, 2),
                'covered_blocks' => count($covered),
                'share' => round($share, 2),
                'created_at' => $p->created_at?->format('d.m.Y'),
            ];
        }

        return ['total' => round($total, 2), 'lines' => $lines];
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

    /** percent-модели ЗП (доля выручки × %) — только для них работает picker поздних оплат. */
    private const PERCENT_SALARY_TYPES = ['percent', 'percent_per_block'];

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

        foreach ($teacher->courses as $course) {
            if (self::isTechnicalCourse($course) || ! in_array($course->salary_type, self::PERCENT_SALARY_TYPES, true)) {
                continue;
            }

            // У курса процент может быть не задан (salary_value=0) — тогда берём
            // процент из текущего расчёта (поле калькулятора).
            $percent = (float) $course->salary_value ?: $fallbackPercent;
            $blockNumbers = $this->blockNumbersFor($course->id);
            // [number => 'Y-m-d'] для датированных блоков — чтобы отсечь ещё не начавшиеся.
            $blockStarts = CourseBlock::query()
                ->where('course_id', $course->id)
                ->whereNotNull('starts_at')
                ->get(['number', 'starts_at'])
                ->mapWithKeys(fn (CourseBlock $cb) => [(int) $cb->number => $cb->starts_at->toDateString()])
                ->all();

            foreach ($this->coursePayments($course->id) as $p) {
                if (in_array($p->tariff, self::NON_REVENUE_TARIFFS, true) || (float) $p->amount <= 0) {
                    continue;
                }

                $covered = $this->coveredBlockNumbers($p, $blockNumbers);
                if (empty($covered)) {
                    continue;
                }
                $share = round($this->accrualAmount($p, false) / count($covered), 2);

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
                        'amount' => round((float) $p->amount, 2),
                        'share' => $share,
                        'percent' => $percent,
                        'teacher_amount' => round($share * $percent / 100, 2),
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
     * Поздние оплаты прошлых блоков — готовые рубли (% уже применён к доле
     * платежа), прибавляются к итогу как есть (без коэффициента и процента).
     */
    public static function blockPayoutTotal(float $base, float $coef, float $teacherPct, float $extrasTotal, float $surcharge = 0.0, float $deduction = 0.0, float $priorBlocksTotal = 0.0): float
    {
        return round(($base * $coef / 100) * ($teacherPct / 100) + $extrasTotal * ($coef / 100) + $surcharge - abs($deduction) + $priorBlocksTotal, 2);
    }

    /**
     * Помесячное начисление по курсу (независимо от окна запроса) — кэшируется.
     *
     * @param  array{gross_before_discount: bool, subtract_returns: bool}  $opts
     * @return array{accrued: array<string,float>, accrued_gross: array<string,float>, accrued_returns: array<string,float>, revenue: array<string,float>, returns: array<string,float>, students: array<int,string>}
     */
    private function courseAccrual(Course $course, array $opts): array
    {
        $key = $course->id.'|'.json_encode($opts);

        return $this->courseAccrualCache[$key] ??= $this->computeCourseAccrual($course, $opts);
    }

    /**
     * @param  array{gross_before_discount: bool, subtract_returns: bool}  $opts
     * @return array{accrued: array<string,float>, accrued_gross: array<string,float>, accrued_returns: array<string,float>, revenue: array<string,float>, returns: array<string,float>, students: array<int,string>}
     */
    private function computeCourseAccrual(Course $course, array $opts): array
    {
        $blockNumbers = $this->blockNumbersFor($course->id);
        $blockMonths = $this->blockMonthsFor($course->id);
        $totalBlocks = max(1, count($blockNumbers));

        $payments = $this->coursePayments($course->id);

        $real = $payments->reject(fn (Payment $p) => in_array($p->tariff, self::NON_REVENUE_TARIFFS, true)
            || (float) $p->amount <= 0);
        $returns = $payments->filter(fn (Payment $p) => $p->tariff === self::EXPENSE_TARIFF);

        // Положительная выручка по месяцам (без возвратов) + ранний месяц студента.
        $revenue = [];
        $returnsByMonth = [];
        $studentEarliest = [];
        foreach ($real as $p) {
            $amount = $this->accrualAmount($p, $opts['gross_before_discount']);
            $shares = $this->recognizedShares($p, $amount, $blockNumbers, $blockMonths);
            foreach ($shares as $m => $amt) {
                $revenue[$m] = ($revenue[$m] ?? 0.0) + $amt;
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
                }
            }
        }

        $type = (string) $course->salary_type;
        $value = (float) $course->salary_value;
        $accruedGross = [];
        $accruedReturns = [];

        switch ($type) {
            case 'percent':
            case 'percent_per_block':
                foreach ($revenue as $m => $amt) {
                    $accruedGross[$m] = $amt * ($value / 100);
                }
                // На ЗП возвраты влияют только в процентных схемах.
                foreach ($returnsByMonth as $m => $amt) {
                    $accruedReturns[$m] = $amt * ($value / 100);
                }
                break;

            case 'fix_per_block':
                $fallback = $this->fallbackMonth($blockMonths, $real);
                foreach ($blockNumbers as $n) {
                    $m = $blockMonths[$n] ?? $fallback;
                    if ($m !== null) {
                        $accruedGross[$m] = ($accruedGross[$m] ?? 0.0) + $value;
                    }
                }
                break;

            case 'fix_total':
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

        // Ролл-форвард: всё, что попало в закрытый месяц преподавателя,
        // переносим в ближайший открытый (нельзя начислять в уже рассчитанный).
        $closed = $this->closedMonthsFor((int) $course->teacher_id);
        if (! empty($closed)) {
            $accrued = $this->remapMonths($accrued, $closed);
            $accruedGross = $this->remapMonths($accruedGross, $closed);
            $accruedReturns = $this->remapMonths($accruedReturns, $closed);
            $revenue = $this->remapMonths($revenue, $closed);
            $returnsByMonth = $this->remapMonths($returnsByMonth, $closed);
            $studentEarliest = array_map(fn (string $m) => $this->rollForwardMonth($m, $closed), $studentEarliest);
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
        $createdMonth = $payment->created_at?->format('Y-m') ?? now()->format('Y-m');

        if ($payment->salary_recognition_month) {
            return [$payment->salary_recognition_month => $amount];
        }

        $covered = $this->coveredBlockNumbers($payment, $blockNumbers);
        if (empty($covered)) {
            return [$createdMonth => $amount];
        }

        $share = $amount / count($covered);
        $out = [];
        foreach ($covered as $n) {
            $month = $blockMonths[$n] ?? $createdMonth;
            $out[$month] = ($out[$month] ?? 0.0) + $share;
        }

        return $out;
    }

    /**
     * Номера блоков, которые покрывает платёж. full / (null,null) → все блоки
     * курса. Переиспользуем DebtorsReport::paymentCovers. Если по списку блоков
     * курса ничего не совпало (платёж за блоки сверх заведённых) — берём прямой
     * диапазон start..end.
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
     * @return \Illuminate\Support\Collection<int, Payment>
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
     * @param  \Illuminate\Support\Collection<int, Payment>  $real
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
     * @param  array{gross_before_discount?: bool, subtract_returns?: bool}  $opts
     * @return array{gross_before_discount: bool, subtract_returns: bool}
     */
    private function normalizeOptions(array $opts): array
    {
        return [
            'gross_before_discount' => (bool) ($opts['gross_before_discount'] ?? false),
            'subtract_returns' => (bool) ($opts['subtract_returns'] ?? true),
        ];
    }
}
