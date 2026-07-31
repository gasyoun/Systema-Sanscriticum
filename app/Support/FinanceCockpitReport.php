<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Services\RevenueScheduleService;
use App\Services\TeacherSalaryService;
use Illuminate\Support\Carbon;
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

    /** Тариф-возврат (сторно оплаты), хранится отрицательной суммой. */
    private const REFUND_TARIFF = 'Расход';

    /** 10 праны = 1 ₽ (config('prana.rate')). */
    private const PRANA_RATE = 10;

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

        $refundOut = abs((float) Payment::query()
            ->where('tariff', self::REFUND_TARIFF)
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
}
