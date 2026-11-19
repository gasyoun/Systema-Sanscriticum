<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Services\RevenueScheduleService;
use App\Services\TeacherSalaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Финансовый штурвал — сборочный слой поверх денежного ядра. Собирает четыре
 * управленческих среза в стиле «трёх китов» (Нескучные финансы): ОПиУ, ДДС,
 * Баланс и платёжный календарь. Данных не создаёт и денежную семантику не
 * трогает — только читает канонические источники (scopes Payment,
 * TeacherSalaryService, DebtorsReport, LeadCostReport) плюс новую расходную
 * сторону Expense (opex).
 *
 * ВАЖНО про инварианты (иначе цифры соврут):
 *  - выручка = только paid()->real()->schoolReceived() минус NON_REVENUE_TARIFFS;
 *    conditional-доступ и прямые платежи на счёт преподавателя — НЕ выручка;
 *  - accrual (ЗП по периоду блока) ≠ cash (выплаты по дате) — это два разных
 *    числа за период, в чём и весь смысл «продукт есть, а денег нет»;
 *  - «Расход» (tariff) в Payment — это возвраты/сторно, НЕ opex; операционные
 *    расходы живут в модели Expense.
 */
class FinanceCockpitReport
{
    /**
     * Тарифы, не образующие выручку: системные расходы/возвраты и зеркала выплат
     * ЗП. Канон — TeacherSalaryService::NON_REVENUE_TARIFFS (там private), держим
     * копию с явной ссылкой; при расхождении — синхронизировать.
     */
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    // REFUND_TARIFF = 'Расход' удалён (H2003, issue #953): тариф «Расход» — это
    // легаси-РАСХОДЫ школы, а не возвраты ученикам. ДДС считал их «возвратами»
    // (строка была мислейблом), а после моста «Расход»→Expense те же деньги
    // попали бы в net дважды — как refundOut И как opexOut. Возвраты теперь
    // считаются по настоящему механизму привязки refund_of_payment_id.

    /** 10 праны = 1 ₽ (config('prana.rate')). */
    private const PRANA_RATE = 10;

    /** as-of кэш начислений earnedThrough(): идентификатор+дата → сумма. */
    private array $earnedThroughCache = [];

    /**
     * Границы месяца 'YYYY-MM' → [начало, конец].
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function monthBounds(string $period): array
    {
        $start = Carbon::parse($period.'-01')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * Поступившая выручка за окно: только касса школы, реальные (не conditional),
     * оплаченные платежи, без возвратов и зеркал ЗП. Признание — по дате платежа
     * (кассовое). Депозиты/пробные ВХОДЯТ (это реальные деньги курса, потом
     * зачитываются в полную оплату — двойного счёта нет, см. Payment scopes).
     */
    public function revenueForWindow(Carbon $start, Carbon $end): float
    {
        return (float) Payment::query()
            ->paid()
            ->real()
            ->schoolReceived()
            ->whereNotIn('tariff', self::NON_REVENUE_TARIFFS)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');
    }

    /**
     * ОПиУ по структуре блоков «Нескучных финансов»:
     *
     *   Выручка
     *   − Переменные расходы (ЗП-COGS accrual + эквайринг)
     *   = Валовая прибыль
     *   − Коммерческие расходы (маркетинг)
     *   − Административные расходы (хостинг, подрядчики, налоги/банк/прочее)
     *   = Операционная прибыль (EBITDA)
     *
     * Ниже EBITDA (проценты, амортизация, налог на прибыль) LMS не моделирует,
     * поэтому чистая прибыль здесь ≈ EBITDA — это честно помечено в UI.
     *
     * @return array{
     *     revenue:float, revenueBasis:string,
     *     salaryCogs:float, acquiring:float, variableTotal:float,
     *     grossProfit:float, grossMargin:?float,
     *     marketing:float, commercialTotal:float,
     *     admin:array<string,float>, adminTotal:float,
     *     opexTotal:float,
     *     ebitda:float, ebitdaMargin:?float
     * }
     */
    public function opiu(string $period): array
    {
        [$start, $end] = $this->monthBounds($period);

        // Кассовый ОПиУ: выручка = платежи по дате оплаты.
        return $this->buildOpiu($period, $this->revenueForWindow($start, $end), 'cash');
    }

    /**
     * ОПиУ по методу НАЧИСЛЕНИЯ (accrual): та же структура и те же расходы, но
     * выручка = сумма признаний RevenueSchedule за месяц (годовой курс, оплаченный
     * сразу, разложен по месяцам блоков — не падает целиком в месяц оплаты).
     * COGS (ЗП) уже accrual, поэтому эта вкладка честно сводит выручку и её
     * себестоимость по одному периоду. Кассовая вкладка сохранена рядом — она
     * нужна для ДДС и сверки с банком.
     *
     * @return array{
     *     revenue:float, revenueBasis:string,
     *     salaryCogs:float, acquiring:float, variableTotal:float,
     *     grossProfit:float, grossMargin:?float,
     *     marketing:float, commercialTotal:float,
     *     admin:array<string,float>, adminTotal:float,
     *     opexTotal:float,
     *     ebitda:float, ebitdaMargin:?float
     * }
     */
    public function opiuAccrual(string $period): array
    {
        return $this->buildOpiu($period, $this->accrualRevenueForPeriod($period), 'accrual');
    }

    /**
     * Признанная (accrual) выручка за месяц — сумма строк субледжера
     * RevenueSchedule. Σ за всё время == Σ кассовой выручки (инвариант раскладки),
     * отличается только помесячное распределение.
     */
    public function accrualRevenueForPeriod(string $period): float
    {
        return app(RevenueScheduleService::class)->recognizedForMonth($period);
    }

    /**
     * Отложенная выручка на конец периода: оплачено кассой − признано
     * (накопительно). «Замороженный» разрыв между кассой и прибылью.
     *
     * @return array{cashReceived:float, recognized:float, deferred:float}
     */
    public function deferredRevenue(string $period): array
    {
        return app(RevenueScheduleService::class)->deferredRevenueAsOf($period);
    }

    /**
     * Сборка ОПиУ с заданной выручкой (кассовой или accrual). Расходная часть у
     * обеих вкладок одинакова — различается только источник строки «Выручка».
     *
     * @return array{
     *     revenue:float, revenueBasis:string,
     *     salaryCogs:float, acquiring:float, variableTotal:float,
     *     grossProfit:float, grossMargin:?float,
     *     marketing:float, commercialTotal:float,
     *     admin:array<string,float>, adminTotal:float,
     *     opexTotal:float,
     *     ebitda:float, ebitdaMargin:?float
     * }
     */
    private function buildOpiu(string $period, float $revenue, string $revenueBasis): array
    {
        [$start, $end] = $this->monthBounds($period);

        // COGS: ЗП преподавателей начислением по периоду блока (accrual).
        $salaryCogs = 0.0;
        foreach (app(TeacherSalaryService::class)->summaryForAll($period) as $row) {
            $salaryCogs += (float) $row['earned_period'];
        }

        // Opex по статьям (кассовый признак — дата траты).
        $opexByCat = Expense::byCategoryForWindow($start, $end);
        $opexTotal = array_sum($opexByCat);

        // Эквайринг — переменный расход (растёт с выручкой), в блок «Переменные».
        $acquiring = (float) ($opexByCat[ExpenseCategory::Acquiring->value] ?? 0.0);
        $variableTotal = $salaryCogs + $acquiring;

        $grossProfit = $revenue - $variableTotal;
        $grossMargin = $revenue > 0 ? $grossProfit / $revenue * 100 : null;

        // Коммерческие — маркетинговый бюджет (Директ) за окно.
        $marketing = LeadCostReport::budgetForWindow($start, $end);
        $commercialTotal = $marketing;

        // Административные — весь остальной opex, кроме эквайринга.
        $admin = [];
        foreach (ExpenseCategory::cases() as $case) {
            if ($case->isVariable()) {
                continue;
            }
            $admin[$case->value] = (float) ($opexByCat[$case->value] ?? 0.0);
        }
        $adminTotal = array_sum($admin);

        $ebitda = $grossProfit - $commercialTotal - $adminTotal;
        $ebitdaMargin = $revenue > 0 ? $ebitda / $revenue * 100 : null;

        return compact(
            'revenue', 'revenueBasis',
            'salaryCogs', 'acquiring', 'variableTotal',
            'grossProfit', 'grossMargin',
            'marketing', 'commercialTotal',
            'admin', 'adminTotal',
            'opexTotal',
            'ebitda', 'ebitdaMargin',
        );
    }

    /**
     * ДДС (кассовый) за месяц: приток − отток. Отток ЗП — все выплаты
     * преподавателям за период по TeacherPayout (регулярные + авансы, обе —
     * реальные деньги на руки). Отток возвратов — тариф «Расход» (хранится
     * отрицательной суммой, берём модуль). Отток opex — модель Expense по дате.
     *
     * @return array{inflow:float, salaryOut:float, refundOut:float, opexOut:float, net:float}
     */
    public function dds(string $period): array
    {
        [$start, $end] = $this->monthBounds($period);

        $inflow = $this->revenueForWindow($start, $end);

        $salaryOut = (float) TeacherPayout::query()
            // Суточные границы, не toDateString(): выплата последнего дня месяца
            // со временем в значении выпадала из окна (issue #935, H1996).
            ->whereBetween('paid_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->sum('amount');

        // Возвраты ученикам — ТОЛЬКО привязанные сторно (refund_of_payment_id),
        // как в RevenueScheduleService::deferredRevenueAsOf. Прежний фильтр по
        // тарифу «Расход» ловил легаси-расходы школы, не возвраты; после моста
        // «Расход»→Expense (H2003) они входят в opexOut — считать их здесь ещё
        // раз значило бы задвоить отток (issue #953).
        $refundOut = abs((float) Payment::query()
            ->whereNotNull('refund_of_payment_id')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount'));

        $opexOut = Expense::totalForWindow($start, $end);

        $net = $inflow - $salaryOut - $refundOut - $opexOut;

        return compact('inflow', 'salaryOut', 'refundOut', 'opexOut', 'net');
    }

    /**
     * Баланс на сейчас: активы и обязательства, собранные из уже существующих
     * кусочков денежного ядра.
     *
     * @return array{debt:float, conditionalCount:int, advances:float,
     *     teacherPayable:float, deposits:float, pranaLiability:float, referral:float}
     */
    public function balance(): array
    {
        $report = app(DebtorsReport::class)->forYears([]);
        $debt = (float) $report->totalDebtForQuery($report->query())['amount'];

        // Conditional-доступ = материалы открыты в кредит, деньги не получены —
        // скрытая дебиторка. Сумма в таких платежах обычно 0 (доступ, не оплата),
        // поэтому меряем ЧИСЛОМ выданных доступов, а не рублями.
        $conditionalCount = Payment::query()->conditional()->count();

        // Непогашенные авансы преподавателям — актив (деньги выданы вперёд).
        $advances = (float) TeacherPayout::query()->unsettledAdvances()->sum('amount');

        // «К выплате» преподавателям (начислено всего − выплачено всего) — пассив.
        $teacherPayable = 0.0;
        foreach (app(TeacherSalaryService::class)->summaryForAll(now()->format('Y-m')) as $row) {
            $teacherPayable += max(0.0, (float) $row['balance']);
        }

        // Непотраченные депозиты/брони — пассив (предоплата за неоказанную услугу).
        // Остаток = amount − consumed_amount: частично зачтённые брони входят
        // только непотраченной частью.
        $deposits = (float) Payment::query()
            ->unconsumedDeposits()
            ->sum(DB::raw('amount - COALESCE(consumed_amount, 0)'));

        // Прана как скидочное обязательство (верхняя оценка: весь баланс праны в
        // рублях; фактически тратится не более 30 % цены покупки).
        $pranaLiability = (float) User::query()->sum('prana_balance') / self::PRANA_RATE;

        // Реферальный кредит — денежное обязательство перед рефереральми.
        $referral = (float) User::query()->sum('referral_credit');

        return compact(
            'debt', 'conditionalCount', 'advances',
            'teacherPayable', 'deposits', 'pranaLiability', 'referral',
        );
    }

    /**
     * Платёжный календарь: ожидаемые приходы (активные обещания в горизонте N
     * дней) + просроченные обещания − ближайший отток преподавателям («к
     * выплате»). gap < 0 — сигнал кассового разрыва.
     *
     * @return array{days:int, expectedInflow:float, expectedCount:int,
     *     overdue:float, overdueCount:int, teacherPayable:float, gap:float}
     */
    public function calendar(int $days = 30): array
    {
        $today = now()->toDateString();
        $horizon = now()->addDays($days)->toDateString();

        $upcoming = PaymentPromise::query()
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->whereDate('promised_at', '>=', $today)
            ->whereDate('promised_at', '<=', $horizon);
        $expectedInflow = (float) (clone $upcoming)->sum('amount');
        $expectedCount = (clone $upcoming)->count();

        $late = PaymentPromise::query()
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->whereDate('promised_at', '<', $today);
        $overdue = (float) (clone $late)->sum('amount');
        $overdueCount = (clone $late)->count();

        $teacherPayable = 0.0;
        foreach (app(TeacherSalaryService::class)->summaryForAll(now()->format('Y-m')) as $row) {
            $teacherPayable += max(0.0, (float) $row['balance']);
        }

        $gap = $expectedInflow + $overdue - $teacherPayable;

        return compact(
            'days', 'expectedInflow', 'expectedCount',
            'overdue', 'overdueCount', 'teacherPayable', 'gap',
        );
    }

    /**
     * Обязательства на дату (as-of): back-computation «к выплате» преподавателям
     * и непогашенных авансов по датируемым каноническим источникам. Баланс
     * канонического ядра точечный (на сейчас, см. balance()), а пос cycle-разрез
     * triple-report (H6705, H5554 gap [1]) требует состояние на конец цикла.
     *
     * Точность честно ограничена источниками:
     *  - начисление признаётся помесячно (recognition month), поэтому as-of
     *    якорится к концу цикла: месяц as-of входит целиком, середина месяца
     *    посчитана быть не может;
     *  - у частично зачтённых авансов нет датированного леджера зачётов —
     *    полный зачёт берётся по дате settled_at, частичный — по текущему
     *    прогрессу settled_amount (зафиксирован в advance_settlement_basis).
     *
     * Остаток при as-of = now воспроизводит balance(): та же семантика
     * max(0, ·) по преподавателю, авансы не уменьшают «к выплате», пока не
     * зачтены. Остальные строки баланса (долг учеников, депозиты, прана,
     * реферальный кредит) не воспроизводимы на дату — их состояние в каноне
     * только текущее, поэтому они остаются в точечном снимке.
     *
     * @return array{teacherPayable:float, advances:float,
     *     accrual_basis:string, advance_settlement_basis:string}
     */
    public function obligationsAsOf(Carbon $asOf): array
    {
        $asOf = $asOf->copy()->endOfDay();
        $salary = app(TeacherSalaryService::class);
        $epoch = Carbon::parse('2000-01-01')->startOfDay();

        // Авансы, выданные на дату: полный зачёт — по дате settled_at (она
        // авторитетна: если зачёт завершится в будущем, на as-of до него
        // зачтённого нет); без settled_at (зачёт всё ещё открыт) — по текущему
        // прогрессу settled_amount, датированного леджера зачётов нет.
        $settledByTeacher = [];
        $advances = 0.0;
        foreach ($this->advancesIssuedThrough($asOf) as $advance) {
            $amount = (float) $advance->amount;
            $settled = match (true) {
                $advance->settled_at !== null && $advance->settled_at->lte($asOf) => $amount,
                $advance->settled_at !== null => 0.0,
                default => min((float) ($advance->settled_amount ?? 0), $amount),
            };
            $settledByTeacher[$advance->teacher_id] = ($settledByTeacher[$advance->teacher_id] ?? 0.0) + $settled;
            $advances += max(0.0, $amount - $settled);
        }

        // Регулярные выплаты на дату — по фактической дате выплаты (paid_at).
        $paidRegular = TeacherPayout::query()
            ->where('type', TeacherPayout::TYPE_REGULAR)
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', $asOf)
            ->selectRaw('teacher_id, SUM(amount) AS total')
            ->groupBy('teacher_id')
            ->pluck('total', 'teacher_id');

        // «К выплате» = начислено (все месяцы признания до конца месяца as-of)
        // − выплачено/зачтено на дату. Переплата не уменьшает обязательства.
        $teacherPayable = 0.0;
        foreach (Teacher::query()->get(['id']) as $teacher) {
            $earnedThrough = $this->earnedThrough($salary, $teacher->id, $epoch, $asOf);
            $paidThrough = (float) ($paidRegular[$teacher->id] ?? 0)
                + ($settledByTeacher[$teacher->id] ?? 0.0);
            $teacherPayable += max(0.0, $earnedThrough - $paidThrough);
        }

        return [
            'teacherPayable' => $teacherPayable,
            'advances' => $advances,
            'accrual_basis' => 'recognition months through the as-of month, cycle-end anchor',
            'advance_settlement_basis' => 'settled_at date when present (a future date means nothing settled by then), current settled_amount progress while settlement is open',
        ];
    }

    /** @return Collection<int, TeacherPayout> */
    private function advancesIssuedThrough(Carbon $asOf)
    {
        return TeacherPayout::query()
            ->where('type', TeacherPayout::TYPE_ADVANCE)
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', $asOf)
            ->get(['teacher_id', 'amount', 'settled_amount', 'settled_at']);
    }

    /**
     * Начислено преподавателю за всё время до конца месяца as-of. Окно
     * [epoch; asOf]: windowSum режет по месяцам признания, месяц as-of входит
     * целиком (пос cycle-якорь — конец месяца). Считается на идентификаторе,
     * чтобы не тащить модель целиком в цикл отчёта.
     */
    private function earnedThrough(TeacherSalaryService $salary, int $teacherId, Carbon $epoch, Carbon $asOf): float
    {
        $key = $teacherId.'|'.$asOf->toDateString();
        if (isset($this->earnedThroughCache[$key])) {
            return $this->earnedThroughCache[$key];
        }

        $teacher = Teacher::query()->findOrFail($teacherId);

        return $this->earnedThroughCache[$key] = (float) $salary->totalForTeacher($teacher, $epoch, $asOf);
    }
}
