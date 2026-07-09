<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FinanceCockpitReport;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Инвест-модель крупной траты (H261, фаза E плана noboring /cases/education).
 *
 * Кейс «Юный чемпион»: финдиректор отговорил собственника открывать филиал,
 * построив инвест-модель на два сценария (NPV / IRR / срок окупаемости). Этот
 * сервис — калькулятор такой модели: по сценарию (капзатраты + доп. выручка и
 * расходы по годам + ставка дисконтирования + горизонт) считает NPV, IRR, срок
 * окупаемости (простой и дисконтированный), точку безубыточности и годовой
 * денежный поток, а также выносит вердикт-светофор.
 *
 * Ничего не хранит и денег не двигает — это forward-looking планировочный
 * инструмент (сценарии вводит человек). Живые данные P&L/LeadCost подтягиваются
 * только как СТАРТОВЫЕ ОРИЕНТИРЫ ({@see defaults()}), чтобы не набивать
 * предположения с нуля.
 *
 * Финансовая математика (NPV/IRR/аннуитет) — стандартные формулы, реализованы
 * минимально (пара функций), без внешней зависимости. IRR ищется бисекцией:
 * медленнее Ньютона, но не расходится на нестандартных потоках.
 */
class InvestmentModelService
{
    public function __construct(
        private readonly StudentUnitEconomicsService $unitEconomics,
        private readonly FinanceCockpitReport $report,
    ) {}

    // ── Ядро: оценка одного сценария ─────────────────────────────────────────

    /**
     * Оценить сценарий крупной траты.
     *
     * Денежный поток строится так:
     *   год 0  = −капзатраты (разовые, вперёд);
     *   год t  = доп. выручка_t − доп. расходы_t (расходы, напр. аренда, плоские;
     *            выручка растёт на growth_pct в год — ramp-up филиала/продукта).
     *
     * @param  array{
     *     label?: string,
     *     capex?: float, annual_revenue?: float, annual_expense?: float,
     *     growth_pct?: float, horizon_years?: int, discount_rate?: float
     * }  $scenario  discount_rate — десятичная доля (0.20), НЕ проценты.
     * @return array<string, mixed>
     */
    public function evaluate(array $scenario): array
    {
        $label = (string) ($scenario['label'] ?? 'Сценарий');
        $capex = max(0.0, (float) ($scenario['capex'] ?? 0.0));
        $revenue = (float) ($scenario['annual_revenue'] ?? 0.0);
        $expense = (float) ($scenario['annual_expense'] ?? 0.0);
        $growth = (float) ($scenario['growth_pct'] ?? 0.0) / 100.0;
        $rate = (float) ($scenario['discount_rate'] ?? 0.0);
        $horizon = max(1, (int) ($scenario['horizon_years'] ?? 1));

        // Построить помесячный ряд по годам.
        $cashflows = [-$capex];
        $rows = [[
            'year' => 0,
            'revenue' => 0.0,
            'expense' => 0.0,
            'capex' => $capex,
            'net' => -$capex,
            'discounted' => -$capex,
        ]];
        for ($t = 1; $t <= $horizon; $t++) {
            $rev = $revenue * (1 + $growth) ** ($t - 1);
            $net = $rev - $expense;
            $cashflows[] = $net;
            $rows[] = [
                'year' => $t,
                'revenue' => Money::round($rev),
                'expense' => Money::round($expense),
                'capex' => 0.0,
                'net' => Money::round($net),
                'discounted' => Money::round($net / (1 + $rate) ** $t),
            ];
        }

        // Накопительные итоги (простой и дисконтированный) — для срока окупаемости
        // и визуализации.
        $cumNet = 0.0;
        $cumDisc = 0.0;
        foreach ($rows as $i => $row) {
            $cumNet += $row['net'];
            $cumDisc += $row['discounted'];
            $rows[$i]['cum_net'] = Money::round($cumNet);
            $rows[$i]['cum_disc'] = Money::round($cumDisc);
        }

        $npv = self::npv($rate, $cashflows);
        $irr = self::irr($cashflows);
        $payback = self::paybackTime($cashflows);
        $discPayback = self::paybackTime(self::discount($rate, $cashflows));
        $annualNet = $revenue - $expense; // поток 1-го года (до роста)
        $breakeven = self::breakevenAnnualNet($capex, $rate, $horizon);

        [$level, $verdict] = $this->verdict($npv, $irr, $rate, $payback);

        return [
            'label' => $label,
            'has_input' => $capex > 0 || $revenue != 0.0 || $expense != 0.0,

            // Вход (эхо, для вёрстки/сравнения).
            'capex' => Money::round($capex),
            'annual_revenue' => Money::round($revenue),
            'annual_expense' => Money::round($expense),
            'annual_net' => Money::round($annualNet),
            'growth_pct' => (float) ($scenario['growth_pct'] ?? 0.0),
            'horizon_years' => $horizon,
            'discount_rate_pct' => round($rate * 100, 2),

            // Выход.
            'npv' => Money::round($npv),
            'npv_positive' => $npv > 0,
            'irr_pct' => $irr === null ? null : round($irr * 100, 2),
            'payback_year' => $payback['year'],
            'payback_time' => $payback['time'] === null ? null : round($payback['time'], 1),
            'disc_payback_year' => $discPayback['year'],
            'disc_payback_time' => $discPayback['time'] === null ? null : round($discPayback['time'], 1),
            'breakeven_annual_net' => is_finite($breakeven) ? Money::round($breakeven) : null,
            'meets_breakeven' => is_finite($breakeven) ? $annualNet >= $breakeven : null,

            'rows' => $rows,

            'level' => $level,
            'verdict' => $verdict,
        ];
    }

    /**
     * Сравнить два сценария и назвать победителя (выше NPV) — как в кейсе
     * «с покупкой» vs «без покупки». null-стороны игнорируются.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>|null  $b
     * @return array{winner:?string, note:string}
     */
    public function compare(array $a, ?array $b): array
    {
        if ($b === null || ! $b['has_input']) {
            return ['winner' => null, 'note' => 'Введите второй сценарий, чтобы сравнить бок о бок.'];
        }
        if (! $a['has_input']) {
            return ['winner' => null, 'note' => 'Введите первый сценарий, чтобы сравнить бок о бок.'];
        }

        // Оба отрицательные — ни один не оправдан (как в кейсе: отказаться от обоих).
        if ($a['npv'] <= 0 && $b['npv'] <= 0) {
            return [
                'winner' => null,
                'note' => 'Оба сценария с отрицательным NPV — ни один не окупается при заданной ставке. '
                    .'Как в кейсе «Юный чемпион»: данные важнее энтузиазма — отказаться.',
            ];
        }

        if ($a['npv'] === $b['npv']) {
            return ['winner' => null, 'note' => 'Сценарии равны по NPV.'];
        }

        $winner = $a['npv'] > $b['npv'] ? $a : $b;

        return [
            'winner' => $winner['label'],
            'note' => 'Выше NPV у сценария «'.$winner['label'].'» ('
                .number_format($winner['npv'], 0, '.', ' ').' ₽).',
        ];
    }

    // ── Стартовые ориентиры из живых данных ──────────────────────────────────

    /**
     * Дефолты: ставка/горизонт из конфига + живые ориентиры из P&L и юнит-
     * экономики (средний чек по LTV, CAC, маржа EBITDA, средняя месячная
     * прибыль по начислению за 12 мес). Это НЕ автоматический сценарий, а
     * ориентиры, на которые опирается ручной ввод крупной траты.
     *
     * @return array<string, mixed>
     */
    public function defaults(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();

        $rate = (float) config('investment.default_discount_rate_pct', 20);
        $horizon = (int) config('investment.default_horizon_years', 5);

        // Юнит-экономика ученика за 12 мес: LTV (≈ средний «чек» за жизнь) и CAC.
        $econ = $this->unitEconomics->forPeriod($asOf->copy()->subMonths(12), $asOf);
        $avgCheck = $econ['found'] ?? false ? ($econ['ltv_mean'] ?? null) : null;
        $cac = $econ['cac'] ?? null;

        // Средняя месячная прибыль и маржа по начислению за 12 мес (trailing).
        $margins = [];
        $profits = [];
        for ($i = 0; $i < 12; $i++) {
            $period = $asOf->copy()->subMonthsNoOverflow($i)->format('Y-m');
            $opiu = $this->report->opiuAccrual($period);
            if ($opiu['ebitdaMargin'] !== null) {
                $margins[] = (float) $opiu['ebitdaMargin'];
            }
            $profits[] = (float) $opiu['ebitda'];
        }
        $avgMargin = $margins !== [] ? array_sum($margins) / count($margins) : null;
        $avgProfit = $profits !== [] ? array_sum($profits) / count($profits) : null;

        return [
            'discount_rate_pct' => $rate,
            'horizon_years' => $horizon,
            'acceptable_payback_years' => (float) config('investment.acceptable_payback_years', 4),

            'avg_check' => $avgCheck === null ? null : Money::round((float) $avgCheck),
            'cac' => $cac === null ? null : Money::round((float) $cac),
            'ebitda_margin_pct' => $avgMargin === null ? null : round($avgMargin, 1),
            'avg_monthly_profit' => $avgProfit === null ? null : Money::round($avgProfit),
        ];
    }

    // ── Чистая финансовая математика (переиспользуемые статические методы) ────

    /**
     * Чистая приведённая стоимость: Σ CF_t / (1+r)^t. Индекс 0 = год 0 (не
     * дисконтируется). rate — десятичная доля (0.2 = 20 %).
     *
     * @param  array<int, float>  $cashflows
     */
    public static function npv(float $rate, array $cashflows): float
    {
        $npv = 0.0;
        foreach (array_values($cashflows) as $t => $amount) {
            $npv += $amount / (1 + $rate) ** $t;
        }

        return $npv;
    }

    /**
     * Внутренняя норма доходности: ставка, при которой NPV = 0. Бисекция на
     * [−99.99 %; растущий верх]; если знак NPV в диапазоне не меняется (поток
     * никогда не окупается или всегда прибылен) — IRR не существует, вернём null.
     *
     * @param  array<int, float>  $cashflows
     */
    public static function irr(array $cashflows): ?float
    {
        $f = static fn (float $r): float => self::npv($r, $cashflows);

        $lo = -0.9999;
        $flo = $f($lo);
        if (abs($flo) < 1e-9) {
            return $lo;
        }

        // Расширяем верхнюю границу, пока не поймаем смену знака.
        $hi = 1.0;
        $fhi = $f($hi);
        $tries = 0;
        while ($flo * $fhi > 0 && $tries < 100) {
            $hi *= 1.5;
            $fhi = $f($hi);
            $tries++;
            if ($hi > 1e7) {
                break;
            }
        }
        if ($flo * $fhi > 0) {
            return null; // нет смены знака — IRR не определена
        }

        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            $fmid = $f($mid);
            if (abs($fmid) < 1e-7) {
                return $mid;
            }
            if ($flo * $fmid <= 0) {
                $hi = $mid;
            } else {
                $lo = $mid;
                $flo = $fmid;
            }
        }

        return ($lo + $hi) / 2;
    }

    /**
     * Срок окупаемости по накопительному потоку: год, в котором накопленный
     * поток впервые становится ≥ 0, плюс дробное время (линейная интерполяция
     * внутри года). Возврат ['year' => ?int, 'time' => ?float]; null — не
     * окупается в пределах ряда.
     *
     * @param  array<int, float>  $cashflows
     * @return array{year:?int, time:?float}
     */
    public static function paybackTime(array $cashflows): array
    {
        $cashflows = array_values($cashflows);
        $cum = 0.0;
        foreach ($cashflows as $t => $amount) {
            $prev = $cum;
            $cum += $amount;
            if ($t > 0 && $cum >= 0 && $prev < 0) {
                // Дефицит на начало года −$prev покрывается потоком года $amount.
                $frac = $amount > 0 ? (-$prev) / $amount : 0.0;

                return ['year' => $t, 'time' => ($t - 1) + $frac];
            }
        }

        return ['year' => null, 'time' => null];
    }

    /**
     * Минимальный ПЛОСКИЙ годовой чистый поток, при котором NPV = 0 за горизонт
     * при ставке r (точка безубыточности проекта): капзатраты ÷ аннуитетный
     * множитель. INF при нулевом множителе.
     */
    public static function breakevenAnnualNet(float $capex, float $rate, int $horizon): float
    {
        $af = self::annuityFactor($rate, $horizon);

        return $af > 0 ? $capex / $af : INF;
    }

    /**
     * Аннуитетный множитель (present value of 1 per period): (1−(1+r)^−n)/r,
     * при r≈0 → n.
     */
    public static function annuityFactor(float $rate, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }
        if (abs($rate) < 1e-9) {
            return (float) $n;
        }

        return (1 - (1 + $rate) ** (-$n)) / $rate;
    }

    /**
     * Дисконтировать ряд: CF_t → CF_t/(1+r)^t (год 0 не меняется).
     *
     * @param  array<int, float>  $cashflows
     * @return array<int, float>
     */
    private static function discount(float $rate, array $cashflows): array
    {
        $out = [];
        foreach (array_values($cashflows) as $t => $amount) {
            $out[] = $amount / (1 + $rate) ** $t;
        }

        return $out;
    }

    // ── Вердикт-светофор ─────────────────────────────────────────────────────

    /**
     * Светофор + текст вердикта. Красный — NPV<0 (IRR ниже ставки: проект
     * разрушает стоимость). Жёлтый — NPV≥0, но окупается дольше приемлемого
     * (замороженные деньги). Зелёный — NPV≥0 и укладывается в порог окупаемости.
     *
     * @param  array{year:?int, time:?float}  $payback
     * @return array{0:string, 1:string}
     */
    private function verdict(float $npv, ?float $irr, float $rate, array $payback): array
    {
        $acceptable = (float) config('investment.acceptable_payback_years', 4);
        $irrTxt = $irr === null ? '' : ' (IRR '.number_format($irr * 100, 1, '.', ' ').' % против ставки '
            .number_format($rate * 100, 1, '.', ' ').' %)';

        if ($npv <= 0) {
            return ['danger', 'Отказаться: проект не окупается — NPV ≤ 0, доходность ниже стоимости капитала'
                .$irrTxt.'. Как в кейсе «Юный чемпион»: данные важнее энтузиазма.'];
        }

        if ($payback['time'] === null || $payback['time'] > $acceptable) {
            $pb = $payback['time'] === null
                ? 'за горизонт не окупается по простому потоку'
                : 'окупается за '.number_format($payback['time'], 1, '.', ' ').' лет (порог '
                    .number_format($acceptable, 1, '.', ' ').')';

            return ['warning', 'С осторожностью: NPV положительный'.$irrTxt.', но '.$pb
                .' — деньги надолго заморожены. Взвесить против альтернатив.'];
        }

        return ['success', 'Проект оправдан: NPV положительный'.$irrTxt
            .', окупается за '.number_format($payback['time'], 1, '.', ' ').' лет (в пределах порога).'];
    }
}
