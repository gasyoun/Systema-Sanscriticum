<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DirectAdSpend;
use App\Models\Lead;
use Illuminate\Support\Carbon;

/**
 * Агрегатор стоимости лида по Директу за произвольное окно дат.
 *
 * Вся проративная логика (бюджет периодов оплаты, попадающих на стык
 * недель/месяцев, делится пропорционально дням) собрана здесь.
 *
 * ВАЖНО про согласованность: в агрегате (график + калькулятор за период) лиды
 * считаются по всему окну корзины БЕЗ пер-записных фильтров utm_source/landing,
 * потому что бюджет суммируется по всем записям периода. Пер-записный фильтр
 * остаётся только для построчной стоимости в таблице DirectAdSpend.
 */
class LeadCostReport
{
    /**
     * Суммарный прораченный бюджет всех записей Директа, попадающий в окно.
     */
    public static function budgetForWindow(Carbon $start, Carbon $end): float
    {
        $total = 0.0;
        foreach (DirectAdSpend::query()->get() as $spend) {
            $total += $spend->budgetForWindow($start, $end);
        }

        return $total;
    }

    /**
     * Стоимость лида за окно: прораченный бюджет ÷ лиды. null при 0 лидов.
     */
    public static function costPerLead(Carbon $start, Carbon $end, ?string $utmSource = null, $landingPageId = null): ?float
    {
        $leads = Lead::countForPeriod($start, $end, $utmSource, $landingPageId);
        if ($leads <= 0) {
            return null;
        }

        return self::budgetForWindow($start, $end) / $leads;
    }

    /**
     * Нарезает [start; end] на корзины заданной гранулярности и считает для
     * каждой прораченный бюджет, лиды и стоимость лида. Крайние корзины
     * обрезаются по границам окна, поэтому сумма корзин = итог за всё окно.
     *
     * @param  string  $granularity  'week' | 'month' | 'period' (весь период одной корзиной)
     * @return array<int, array{label:string, start:Carbon, end:Carbon, budget:float, leads:int, cost:?float}>
     */
    public static function buckets(Carbon $start, Carbon $end, string $granularity): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        if ($end->lt($start)) {
            return [];
        }

        $buckets = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $bucketEnd = match ($granularity) {
                'week' => $cursor->copy()->endOfWeek(),
                'month' => $cursor->copy()->endOfMonth(),
                default => $end->copy(), // 'period' — одна корзина на всё окно
            };
            $clampedEnd = $bucketEnd->lt($end) ? $bucketEnd->copy()->startOfDay() : $end->copy();

            $budget = self::budgetForWindow($cursor, $clampedEnd);
            $leads = Lead::countForPeriod($cursor, $clampedEnd);

            $buckets[] = [
                'label' => self::label($cursor, $clampedEnd, $granularity),
                'start' => $cursor->copy(),
                'end' => $clampedEnd->copy(),
                'budget' => $budget,
                'leads' => $leads,
                'cost' => $leads > 0 ? $budget / $leads : null,
            ];

            $cursor = $clampedEnd->copy()->addDay()->startOfDay();
        }

        return $buckets;
    }

    private static function label(Carbon $start, Carbon $end, string $granularity): string
    {
        return match ($granularity) {
            'month' => $start->translatedFormat('M Y'),
            'week' => $start->format('d.m').'–'.$end->format('d.m'),
            default => $start->format('d.m.Y').'–'.$end->format('d.m.Y'),
        };
    }
}
