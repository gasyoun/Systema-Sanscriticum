<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Канонический алгоритм «оплаченный блок → месяц признания», общий для начисления
 * ЗП (TeacherSalaryService) и признания выручки (RevenueRecognitionService).
 *
 * Чистые, не обращающиеся к БД и не кэширующие функции — только арифметика
 * раскладки. Резолюция самих блоков курса (номера блоков, месяцы их старта) и все
 * request-scoped кэши остаются в вызывающих сервисах: TeacherSalaryService держит
 * свой fallback 1..totalBlocks и денежно-критичные кэши, RevenueRecognitionService
 * — свои. Здесь живёт ровно та логика, что до H349 была байт-в-байт продублирована
 * в обоих сервисах (метод coveredBlockNumbers и цикл раскладки по месяцам).
 *
 * @see TeacherSalaryService::recognizedShares
 * @see RevenueRecognitionService::sharesForPayment
 * @see docs/revenue-recognition.md
 */
final class BlockMonthRecognition
{
    /**
     * Номера блоков, которые покрывает платёж с диапазоном [$start; $end].
     * (null,null) → все блоки курса (full/депозит/legacy). Переиспользует
     * DebtorsReport::paymentCovers. Если по списку заведённых блоков курса ничего
     * не совпало (платёж за блоки сверх заведённых) — берём прямой диапазон
     * start..end.
     *
     * @param  list<int>  $blockNumbers  отсортированные номера блоков курса
     * @return list<int>
     */
    public static function coveredBlockNumbers(?int $start, ?int $end, array $blockNumbers): array
    {
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
     * Раскладка суммы по месяцам признания: равная доля за каждый покрытый блок в
     * месяце этого блока; блок без даты старта падает на $createdMonth. Инвариант:
     * Σ долей == $amount (с точностью до плавающей арифметики).
     *
     * @param  list<int>  $covered  покрытые номера блоков (непустой)
     * @param  array<int, string>  $blockMonths  [block_number => 'Y-m'] только для датированных блоков
     * @return array<string, float> ['YYYY-MM' => сумма]
     */
    public static function distribute(float $amount, array $covered, array $blockMonths, string $createdMonth): array
    {
        $share = $amount / count($covered);

        $out = [];
        foreach ($covered as $n) {
            $month = $blockMonths[$n] ?? $createdMonth;
            $out[$month] = ($out[$month] ?? 0.0) + $share;
        }

        return $out;
    }
}
