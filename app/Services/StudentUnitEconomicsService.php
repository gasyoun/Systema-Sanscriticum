<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdPostSpend;
use App\Models\Course;
use App\Models\Payment;
use App\Support\LeadCostReport;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Юнит-экономика УЧЕНИКА: LTV / CAC / retention / churn / месяцев до окупаемости
 * CAC — вторая половина уравнения, которую LeadCost-дашборд (сторона CAC) не
 * считает (H256).
 *
 * В отличие от {@see UnitEconomicsService} (экономика одного УРОКА одного блока),
 * единица анализа здесь — ученик за свою жизнь: сколько стоило его привлечь,
 * сколько он принёс, как долго остаётся и за сколько месяцев окупается.
 *
 * Ничего не хранит — считает из живой БД. Пороги светофора берутся из
 * config/unit_economics.php, а не зашиты в вёрстку.
 *
 * Смысл — из антикейса школы «Лингвистик»: продукт хороший, а денег нет, потому
 * что CAC > LTV и окупаемость требовала ≥9 месяцев удержания, которого не было.
 *
 * Тарифы, исключённые из выручки: 'Расход' (техрасход/возврат) и 'salary_payout'
 * (выплата ЗП) — это бухгалтерские оттоки, не доход ученика. Возвраты
 * (отрицательные суммы на обычных тарифах) остаются: нетто корректно уменьшает LTV.
 */
class StudentUnitEconomicsService
{
    /** Тарифы-оттоки, не образующие выручку ученика. */
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    /** Тарифы-предоплаты: реальные деньги, но НЕ покупка курса (не «привлечение»). */
    private const PRE_PURCHASE_TARIFFS = ['deposit', 'trial'];

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        // Все платежи-выручка (реальные оплаченные, кроме расходов/выплат ЗП),
        // сгруппированные по ученику. Одна выборка на весь расчёт.
        $revenueByUser = $this->revenuePaymentsByUser();

        // Момент привлечения ученика = его первая доступо-открывающая покупка
        // курса (без брони/пробного), сумма > 0. Карта user_id → [date, course_id].
        $acquisition = $this->firstAcquisitionByUser($revenueByUser);

        // Когорта периода: ученики, чья ПЕРВАЯ покупка попала в окно [start; end].
        $cohort = $acquisition->filter(
            fn (array $a) => $a['date']->gte($start) && $a['date']->lte($end)
        );
        $cohortSize = $cohort->count();

        // ── CAC: суммарный маркетинг за окно ÷ привлечённые ученики ──────────
        $directBudget = LeadCostReport::budgetForWindow($start, $end);
        $postBudget = (float) AdPostSpend::query()
            ->whereBetween('posted_on', [$start->toDateString(), $end->toDateString()])
            ->sum('budget');
        $totalSpend = $directBudget + $postBudget;
        $cac = $cohortSize > 0 ? $totalSpend / $cohortSize : null;

        if ($cohortSize === 0) {
            return $this->emptyResult($start, $end, $totalSpend, $directBudget, $postBudget);
        }

        // ── LTV: пожизненная выручка каждого ученика когорты ────────────────
        $ltvValues = [];
        $spanMonthsTotal = 0;   // суммарная длительность отношений (мес.)
        $revenueTotal = 0.0;
        foreach ($cohort as $userId => $_a) {
            $payments = $revenueByUser->get($userId, collect());
            $ltv = (float) $payments->sum('amount');
            $ltvValues[] = $ltv;
            $revenueTotal += $ltv;
            $spanMonthsTotal += $this->spanMonths($payments);
        }

        $ltvMean = $revenueTotal / $cohortSize;
        $ltvMedian = $this->median($ltvValues);

        // Средний месячный доход с ученика = вся выручка когорты ÷ суммарная
        // длительность отношений (в «ученико-месяцах»). Из него — окупаемость CAC.
        $arpuMonthly = $spanMonthsTotal > 0 ? $revenueTotal / $spanMonthsTotal : null;
        $avgSpanMonths = $spanMonthsTotal / $cohortSize;
        $monthsToPayback = ($cac !== null && $arpuMonthly !== null && $arpuMonthly > 0)
            ? $cac / $arpuMonthly
            : null;

        $ltvCacRatio = ($cac !== null && $cac > 0) ? $ltvMean / $cac : null;

        // ── Churn: доля ушедших (нет оплат N месяцев к концу периода) ────────
        $churnMonths = (int) config('unit_economics.churn_months', 6);
        $churned = 0;
        foreach ($cohort as $userId => $_a) {
            if ($this->monthsSinceLastPayment($revenueByUser->get($userId, collect()), $end) >= $churnMonths) {
                $churned++;
            }
        }
        $churnRate = $churned / $cohortSize;

        return [
            'found' => true,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),

            'cohort_size' => $cohortSize,
            'cohort_hint' => 'ученики, чья первая покупка курса пришлась на период',

            // Маркетинг / CAC.
            'spend_total' => Money::round($totalSpend),
            'spend_direct' => Money::round($directBudget),
            'spend_posts' => Money::round($postBudget),
            'cac' => $cac === null ? null : Money::round($cac),

            // LTV.
            'ltv_mean' => Money::round($ltvMean),
            'ltv_median' => $ltvMedian === null ? null : Money::round($ltvMedian),
            'revenue_total' => Money::round($revenueTotal),

            // Отношение и окупаемость.
            'ltv_cac_ratio' => $ltvCacRatio === null ? null : round($ltvCacRatio, 2),
            'ltv_cac_color' => self::ltvCacColor($ltvCacRatio),
            'arpu_monthly' => $arpuMonthly === null ? null : Money::round($arpuMonthly),
            'months_to_payback' => $monthsToPayback === null ? null : round($monthsToPayback, 1),
            'payback_color' => self::paybackColor($monthsToPayback),

            // Удержание.
            'avg_span_months' => round($avgSpanMonths, 1),
            'churn_months' => $churnMonths,
            'churn_rate' => round($churnRate * 100, 1),
            'retention_rate' => round((1 - $churnRate) * 100, 1),
            'retention_curve' => $this->retentionCurve($cohort, $revenueByUser, $end),
            'cohort_months' => $this->cohortMonthsTable($cohort, $revenueByUser, $end),

            // Разрез по курсу (продукт привлечения).
            'by_course' => $this->byCourse($cohort, $revenueByUser, $cac),
        ];
    }

    // ── Светофор ────────────────────────────────────────────────────────────

    /** Цвет для отношения LTV/CAC по порогам из конфига. */
    public static function ltvCacColor(?float $ratio): string
    {
        if ($ratio === null) {
            return 'gray';
        }
        $green = (float) config('unit_economics.ltv_cac.green', 3.0);
        $red = (float) config('unit_economics.ltv_cac.red', 1.0);

        return $ratio >= $green ? 'success' : ($ratio < $red ? 'danger' : 'warning');
    }

    /** Цвет для «месяцев до окупаемости CAC» по порогам из конфига. */
    public static function paybackColor(?float $months): string
    {
        if ($months === null) {
            return 'gray';
        }
        $green = (float) config('unit_economics.payback.green_months', 3.0);
        $red = (float) config('unit_economics.payback.red_months', 9.0);

        return $months <= $green ? 'success' : ($months > $red ? 'danger' : 'warning');
    }

    // ── Кривая удержания и когортная таблица ─────────────────────────────────

    /**
     * Агрегатная кривая удержания: для смещения k месяцев от первой покупки —
     * доля учеников когорты, у кого была оплата в месяц (первый + k). Знаменатель
     * — только «успевшие» ученики (у кого месяц первый+k уже наступил к $end),
     * иначе свежие когорты искусственно занижают удержание.
     *
     * @return array<int, array{k:int, active:int, eligible:int, rate:?float}>
     */
    private function retentionCurve(Collection $cohort, Collection $revenueByUser, Carbon $end): array
    {
        $horizon = (int) config('unit_economics.retention_horizon_months', 12);
        $endMonth = $end->copy()->startOfMonth();

        // Заранее: для каждого ученика — месяц первой покупки и множество активных месяцев.
        $first = [];
        $activeMonths = [];
        foreach ($cohort as $userId => $_a) {
            $payments = $revenueByUser->get($userId, collect());
            $first[$userId] = $this->firstPaymentMonth($payments);
            $activeMonths[$userId] = $this->activeMonthKeys($payments);
        }

        $curve = [];
        for ($k = 0; $k <= $horizon; $k++) {
            $active = 0;
            $eligible = 0;
            foreach ($cohort as $userId => $_a) {
                $firstMonth = $first[$userId];
                if ($firstMonth === null) {
                    continue;
                }
                $target = $firstMonth->copy()->addMonths($k);
                if ($target->gt($endMonth)) {
                    continue; // месяц ещё не наступил — ученик не «успел»
                }
                $eligible++;
                if (in_array($target->format('Y-m'), $activeMonths[$userId], true)) {
                    $active++;
                }
            }
            $curve[] = [
                'k' => $k,
                'active' => $active,
                'eligible' => $eligible,
                'rate' => $eligible > 0 ? round($active / $eligible * 100, 1) : null,
            ];
        }

        return $curve;
    }

    /**
     * Таблица по когортам месяца первой покупки: размер и удержание на 1/3/6/12 мес.
     *
     * @return array<int, array{label:string, size:int, r:array<int,?float>}>
     */
    private function cohortMonthsTable(Collection $cohort, Collection $revenueByUser, Carbon $end): array
    {
        $endMonth = $end->copy()->startOfMonth();
        $offsets = [1, 3, 6, 12];

        // Сгруппировать учеников по месяцу первой покупки.
        $groups = [];
        foreach ($cohort as $userId => $_a) {
            $payments = $revenueByUser->get($userId, collect());
            $firstMonth = $this->firstPaymentMonth($payments);
            if ($firstMonth === null) {
                continue;
            }
            $key = $firstMonth->format('Y-m');
            $groups[$key] ??= ['month' => $firstMonth->copy(), 'users' => []];
            $groups[$key]['users'][] = ['first' => $firstMonth, 'active' => $this->activeMonthKeys($payments)];
        }
        ksort($groups);

        $rows = [];
        foreach ($groups as $g) {
            $size = count($g['users']);
            $r = [];
            foreach ($offsets as $k) {
                $active = 0;
                $eligible = 0;
                foreach ($g['users'] as $u) {
                    $target = $u['first']->copy()->addMonths($k);
                    if ($target->gt($endMonth)) {
                        continue;
                    }
                    $eligible++;
                    if (in_array($target->format('Y-m'), $u['active'], true)) {
                        $active++;
                    }
                }
                $r[$k] = $eligible > 0 ? round($active / $eligible * 100, 1) : null;
            }
            $rows[] = [
                'label' => $g['month']->translatedFormat('LLLL Y'),
                'size' => $size,
                'r' => $r,
            ];
        }

        return $rows;
    }

    // ── Разрез по курсу ──────────────────────────────────────────────────────

    /**
     * Метрики по продукту привлечения: ученик отнесён к курсу его ПЕРВОЙ покупки.
     * CAC на ученика берётся общий (реклама не разбита по курсам), поэтому разное
     * LTV/CAC по курсам показывает, какой продукт убыточен именно по привлечению.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byCourse(Collection $cohort, Collection $revenueByUser, ?float $cac): array
    {
        $titles = Course::query()->pluck('title', 'id');

        $buckets = [];
        foreach ($cohort as $userId => $a) {
            $courseId = $a['course_id'];
            $buckets[$courseId] ??= [];
            $buckets[$courseId][] = (float) $revenueByUser->get($userId, collect())->sum('amount');
        }

        $rows = [];
        foreach ($buckets as $courseId => $ltvs) {
            $size = count($ltvs);
            $mean = array_sum($ltvs) / $size;
            $ratio = ($cac !== null && $cac > 0) ? $mean / $cac : null;
            $rows[] = [
                'course_id' => $courseId,
                'title' => $courseId ? (string) ($titles[$courseId] ?? 'Курс #'.$courseId) : 'Без курса',
                'students' => $size,
                'ltv_mean' => Money::round($mean),
                'ltv_median' => Money::round($this->median($ltvs) ?? 0.0),
                'revenue_total' => Money::round(array_sum($ltvs)),
                'ltv_cac_ratio' => $ratio === null ? null : round($ratio, 2),
                'ltv_cac_color' => self::ltvCacColor($ratio),
            ];
        }

        usort($rows, fn ($x, $y) => $y['students'] <=> $x['students']);

        return $rows;
    }

    // ── Выборки и агрегаты ───────────────────────────────────────────────────

    /**
     * Все платежи-выручка, сгруппированные по user_id (пустой user_id отброшен).
     *
     * @return Collection<int, Collection<int, Payment>>
     */
    /**
     * Публичный якорь привлечения (H3764): момент первой доступо-открывающей
     * покупки на каждого ученика. Активация (O2) считается ОТ НЕГО же, чтобы
     * «сколько у нас привлечённых учеников» не имело двух разных ответов на
     * двух страницах админки.
     *
     * @return Collection<int, array{date:Carbon, course_id:?int}>
     */
    public function acquisitionAnchors(): Collection
    {
        return $this->firstAcquisitionByUser($this->revenuePaymentsByUser());
    }

    private function revenuePaymentsByUser(): Collection
    {
        return Payment::query()
            ->paid()
            ->real()
            ->whereNotNull('user_id')
            ->whereNotIn('tariff', self::NON_REVENUE_TARIFFS)
            ->get(['id', 'user_id', 'course_id', 'amount', 'tariff', 'created_at'])
            ->groupBy('user_id');
    }

    /**
     * Для каждого ученика — момент первой доступо-открывающей покупки курса
     * (без брони/пробного, сумма > 0) и курс этой покупки.
     *
     * @param  Collection<int, Collection<int, Payment>>  $revenueByUser
     * @return Collection<int, array{date:Carbon, course_id:?int}>
     */
    private function firstAcquisitionByUser(Collection $revenueByUser): Collection
    {
        return $revenueByUser->map(function (Collection $payments) {
            $acq = $payments
                ->filter(fn (Payment $p) => (float) $p->amount > 0
                    && ! in_array($p->tariff, self::PRE_PURCHASE_TARIFFS, true))
                ->sortBy('created_at')
                ->first();

            if (! $acq) {
                return null;
            }

            return [
                'date' => Carbon::parse($acq->created_at),
                'course_id' => $acq->course_id ? (int) $acq->course_id : null,
            ];
        })->filter();
    }

    /**
     * Длительность отношений в месяцах: от первой до последней выручной оплаты
     * (с суммой > 0) включительно, минимум 1.
     *
     * @param  Collection<int, Payment>  $payments
     */
    private function spanMonths(Collection $payments): int
    {
        $dates = $payments
            ->filter(fn (Payment $p) => (float) $p->amount > 0)
            ->map(fn (Payment $p) => Carbon::parse($p->created_at)->startOfMonth());

        if ($dates->isEmpty()) {
            return 1;
        }

        $min = $dates->min();
        $max = $dates->max();

        return (int) $min->diffInMonths($max) + 1;
    }

    /** Месяц первой выручной оплаты (Carbon startOfMonth) или null. */
    private function firstPaymentMonth(Collection $payments): ?Carbon
    {
        $dates = $payments
            ->filter(fn (Payment $p) => (float) $p->amount > 0)
            ->map(fn (Payment $p) => Carbon::parse($p->created_at)->startOfMonth());

        return $dates->isEmpty() ? null : $dates->min();
    }

    /**
     * Множество месяцев-ключей 'Y-m', в которых у ученика была оплата (сумма > 0).
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<int, string>
     */
    private function activeMonthKeys(Collection $payments): array
    {
        return $payments
            ->filter(fn (Payment $p) => (float) $p->amount > 0)
            ->map(fn (Payment $p) => Carbon::parse($p->created_at)->format('Y-m'))
            ->unique()
            ->values()
            ->all();
    }

    /** Сколько полных месяцев прошло от последней оплаты до $asOf. */
    private function monthsSinceLastPayment(Collection $payments, Carbon $asOf): int
    {
        $last = $payments
            ->filter(fn (Payment $p) => (float) $p->amount > 0)
            ->map(fn (Payment $p) => Carbon::parse($p->created_at)->startOfMonth())
            ->max();

        if (! $last) {
            return PHP_INT_MAX;
        }

        return (int) $last->diffInMonths($asOf->copy()->startOfMonth());
    }

    /** Медиана массива значений (null для пустого). */
    private function median(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $values[$mid]
            : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(Carbon $start, Carbon $end, float $spend, float $direct, float $posts): array
    {
        return [
            'found' => false,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'cohort_size' => 0,
            'spend_total' => Money::round($spend),
            'spend_direct' => Money::round($direct),
            'spend_posts' => Money::round($posts),
            'cac' => null,
        ];
    }
}
