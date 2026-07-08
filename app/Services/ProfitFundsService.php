<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FinanceCockpitReport;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Система фондов прибыли (H259, фаза D плана noboring /cases/education).
 *
 * Кейс «Аура»: чистая прибыль не «утекает на карту владельца», а распределяется
 * по фондам (дефолт 60 % дивиденды · 20 % резерв · 10 % команда · 10 % компания).
 * Резерв — подушка от кассовых разрывов, поэтому его отдельно СВЕРЯЕМ С КАССОЙ:
 * начисленный резерв бесполезен, если под него нет реальных денег.
 *
 * Прибыль для распределения = ЧИСТАЯ ПРИБЫЛЬ ПО НАЧИСЛЕНИЮ (EBITDA из
 * ОПиУ-accrual, фаза B) за месяц. Распределяем только ПОЛОЖИТЕЛЬНУЮ прибыль:
 * убыточный месяц фонды не пополняет и не «отнимает» (фонд — это заработанное,
 * а не долг). Доли и окно накопления — config/profit_funds.php.
 *
 * Ничего не хранит — считает из живого ОПиУ и ДДС. «Накопленный фонд» — сумма
 * месячных отчислений за trailing-окно; это управленческая витрина earmark'ов,
 * а не бухгалтерский счёт (у LMS нет отдельного банковского леджера — честно
 * помечаем это в UI).
 */
class ProfitFundsService
{
    public function __construct(private readonly FinanceCockpitReport $report) {}

    /**
     * Полный снимок фондов: распределение прибыли текущего месяца, накопление за
     * окно и сверка резерва с накопленной кассой. Единая точка для страницы,
     * KPI-панели делегирования и недельного дайджеста.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();
        $period = $asOf->format('Y-m');

        [$funds, $shareNote] = $this->normalizedFunds();
        $reserveKey = (string) config('profit_funds.reserve_key', 'reserve');
        $windowMonths = (int) config('profit_funds.accumulation_window_months', 12);

        // ── Прибыль текущего месяца по начислению и её раскладка по фондам. ──
        $currentProfit = $this->accrualProfit($period);
        $current = $this->distribute($currentProfit, $funds);

        // ── Накопление за окно: помесячно копим отчисления и кассу ДДС. ──────
        $accumulated = [];
        foreach (array_keys($funds) as $key) {
            $accumulated[$key] = 0.0;
        }
        $accumulatedProfit = 0.0;
        $accumulatedCash = 0.0;
        $months = [];

        for ($i = $windowMonths - 1; $i >= 0; $i--) {
            $m = $asOf->copy()->subMonthsNoOverflow($i)->format('Y-m');
            $profit = $this->accrualProfit($m);
            $split = $this->distribute($profit, $funds);
            foreach ($split['allocations'] as $key => $amount) {
                $accumulated[$key] += $amount;
            }
            $accumulatedProfit += max(0.0, $profit);
            // Чистый денежный поток месяца (ДДС) — реальные деньги, которыми
            // может быть обеспечен резерв.
            $accumulatedCash += (float) $this->report->dds($m)['net'];

            $months[] = [
                'period' => $m,
                'profit' => Money::round($profit),
                'reserve' => Money::round($split['allocations'][$reserveKey] ?? 0.0),
                'cash_net' => Money::round((float) $this->report->dds($m)['net']),
            ];
        }
        foreach ($accumulated as $key => $amount) {
            $accumulated[$key] = Money::round($amount);
        }

        // ── Сверка резерва с кассой: накопленный резерв обеспечен реальными
        //    деньгами (накопленный чистый поток ДДС)? ────────────────────────
        $reserveAccrued = $accumulated[$reserveKey] ?? 0.0;
        $cashCoversReserve = $reserveAccrued > 0 ? $accumulatedCash / $reserveAccrued : null;
        $reserveLevel = $this->reserveLevel($reserveAccrued, $accumulatedCash, $cashCoversReserve);

        return [
            'as_of' => $asOf->toDateTimeString(),
            'period' => $period,
            'window_months' => $windowMonths,

            // Метаданные фондов (ключ → label/hint/доля) для вёрстки.
            'funds' => $this->fundsMeta($funds),
            'reserve_key' => $reserveKey,
            'share_note' => $shareNote,

            // Текущий месяц.
            'current_profit' => Money::round($currentProfit),
            'current_profit_positive' => $currentProfit > 0,
            'current_allocations' => array_map([Money::class, 'round'], $current['allocations']),

            // Накопление за окно.
            'accumulated' => $accumulated,
            'accumulated_profit' => Money::round($accumulatedProfit),
            'months' => $months,

            // Сверка резерва с кассой.
            'reserve_accrued' => Money::round($reserveAccrued),
            'accumulated_cash' => Money::round($accumulatedCash),
            'cash_covers_reserve_pct' => $cashCoversReserve === null ? null : round($cashCoversReserve * 100, 1),
            'reserve_level' => $reserveLevel,
            'reserve_note' => $this->reserveNote($reserveLevel, $reserveAccrued, $accumulatedCash),

            // Итоговый цвет светофора фазы D для KPI-панели.
            'level' => $reserveLevel,
        ];
    }

    /** Чистая прибыль по начислению (EBITDA ОПиУ-accrual) за месяц 'YYYY-MM'. */
    private function accrualProfit(string $period): float
    {
        return (float) $this->report->opiuAccrual($period)['ebitda'];
    }

    /**
     * Разложить прибыль по фондам. Отрицательную/нулевую прибыль не распределяем
     * (фонд пополняется только заработанным).
     *
     * @param  array<string, float>  $funds  ключ → нормированная доля
     * @return array{profit:float, allocations:array<string,float>}
     */
    private function distribute(float $profit, array $funds): array
    {
        $base = max(0.0, $profit);
        $allocations = [];
        foreach ($funds as $key => $share) {
            $allocations[$key] = $base * $share;
        }

        return ['profit' => $profit, 'allocations' => $allocations];
    }

    /**
     * Доли фондов, нормированные к сумме 1.0. Если .env задал доли, не дающие в
     * сумме единицу, нормируем и возвращаем человекочитаемую пометку — молча не
     * «теряем» и не «дорисовываем» прибыль.
     *
     * @return array{0: array<string,float>, 1: ?string}
     */
    private function normalizedFunds(): array
    {
        $cfg = (array) config('profit_funds.funds', []);
        $raw = [];
        foreach ($cfg as $key => $meta) {
            $raw[$key] = (float) ($meta['share'] ?? 0.0);
        }

        $sum = array_sum($raw);
        if ($sum <= 0.0) {
            // Дегенеративная конфигурация — равные доли, чтобы не делить на ноль.
            $n = max(1, count($raw));
            $equal = array_fill_keys(array_keys($raw), 1.0 / $n);

            return [$equal, 'Доли фондов в конфиге пусты — временно равные доли.'];
        }

        if (abs($sum - 1.0) < 0.0001) {
            return [$raw, null];
        }

        $normalized = [];
        foreach ($raw as $key => $share) {
            $normalized[$key] = $share / $sum;
        }

        return [
            $normalized,
            'Доли фондов в конфиге дают '.round($sum * 100).' %, нормированы к 100 %.',
        ];
    }

    /**
     * Метаданные фондов для вёрстки: ключ → label, hint, нормированная доля.
     *
     * @param  array<string, float>  $funds  нормированные доли
     * @return array<string, array{label:string, hint:string, share:float, share_pct:float}>
     */
    private function fundsMeta(array $funds): array
    {
        $cfg = (array) config('profit_funds.funds', []);
        $meta = [];
        foreach ($funds as $key => $share) {
            $meta[$key] = [
                'label' => (string) ($cfg[$key]['label'] ?? $key),
                'hint' => (string) ($cfg[$key]['hint'] ?? ''),
                'share' => $share,
                'share_pct' => round($share * 100, 1),
            ];
        }

        return $meta;
    }

    /** Светофор обеспеченности резерва накопленной кассой. */
    private function reserveLevel(float $reserveAccrued, float $accumulatedCash, ?float $ratio): string
    {
        if ($reserveAccrued <= 0.0) {
            return 'gray';
        }
        if ($accumulatedCash <= 0.0) {
            return 'danger';
        }
        $warn = (float) config('profit_funds.reserve_cash_warn_ratio', 0.8);

        return $ratio >= 1.0 ? 'success' : ($ratio >= $warn ? 'warning' : 'danger');
    }

    private function reserveNote(string $level, float $reserveAccrued, float $accumulatedCash): string
    {
        return match ($level) {
            'success' => 'Резерв полностью обеспечен реальной кассой.',
            'warning' => 'Резерв обеспечен кассой лишь частично — пополняйте подушку деньгами, не только начислением.',
            'danger' => 'Резерв «на бумаге»: накопленной кассы под него нет ('
                .$this->rub($accumulatedCash).' против '.$this->rub($reserveAccrued).' резерва). Риск кассового разрыва.',
            default => 'Резерв ещё не накоплен (нет прибыльных месяцев в окне).',
        };
    }

    private function rub(float $v): string
    {
        return number_format($v, 0, '.', ' ').' ₽';
    }
}
