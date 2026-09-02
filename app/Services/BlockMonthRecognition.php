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

    /**
     * Механизмы атрибуции месяца признания (H3951). Каждая раскладка обязана
     * назвать, ЧЕМ она получена — «наследие» тихого смешения override, раскладки
     * по блокам и падения на дату платежа было тем самым дефектом, ради которого
     * заведён H3951: строка в отчёте выглядела одинаково независимо от того,
     * признана она по колонке или эвристикой.
     */
    public const BY_COLUMN = 'column';                       // salary_recognition_month (ручной override)
    public const BY_BLOCKS = 'blocks';                       // раскладка по месяцам покрытых блоков
    public const BY_CREATED = 'created';                     // платёж без курса/блоков → месяц оплаты
    public const BY_DEGENERATE_FALLBACK = 'blocks_degenerate'; // расписание курса вырождено → месяц оплаты

    /**
     * Расписание курса ВЫРОЖДЕНО: у всех датированных блоков один и тот же
     * starts_at. Это подпись массового бэкофилла (FINDINGS §621 «одинаковая дата
     * у десятков строк — отметка миграции, а не событие жизненного цикла»), а не
     * реального календаря: у настоящего курса блоки идут в разные дни.
     *
     * Пока расписание вырождено, признание «по месяцу блока» не несёт информации
     * — оно уносит ВСЮ сумму в месяц штампа миграции (для курса 266 это
     * 2025-03, на 17 месяцев назад от даты предоплаты). Просрочка и предоплата на
     * НАСТОЯЩЕМ расписании этим предикатом не задеваются: там даты блоков разные.
     *
     * Один датированный блок — не вырождение (одна дата тривиально «одинакова»).
     *
     * @param  array<int, string>  $blockDates  [block_number => 'Y-m-d'] только датированные блоки курса
     */
    public static function scheduleIsDegenerate(array $blockDates): bool
    {
        $dated = array_filter($blockDates, static fn ($d) => $d !== null && $d !== '');

        return count($dated) >= 2 && count(array_unique($dated)) === 1;
    }

    /**
     * Полная атрибуция месяца признания С НАЗВАННЫМ МЕХАНИЗМОМ (H3951).
     * Возвращает и раскладку, и то, чем она получена, — вызывающий код обязан
     * уметь сказать по каждой строке, признана она колонкой или эвристикой.
     *
     * Порядок ровно тот, что был до H3951 (поведение при $degenerateGuard=false
     * байт-в-байт прежнее):
     *   1. salary_recognition_month → вся сумма в один месяц (BY_COLUMN);
     *   2. нет покрытых блоков → месяц оплаты (BY_CREATED);
     *   3. иначе раскладка по месяцам блоков (BY_BLOCKS).
     * Флаг $degenerateGuard (config revenue.recognition_degenerate_schedule_guard,
     * дефолт OFF) добавляет шаг 2.5: вырожденное расписание курса → месяц оплаты
     * (BY_DEGENERATE_FALLBACK). Пока флаг выключен, механизм всё равно
     * репортится как BY_BLOCKS, а признак `degenerate` остаётся true — это и есть
     * «named fallback, не тихое смешение»: аудит видит затронутые строки ДО того,
     * как поведение поменяется.
     *
     * @param  list<int>  $covered  покрытые номера блоков
     * @param  array<int, string>  $blockMonths  [block_number => 'Y-m']
     * @param  array<int, string>  $blockDates  [block_number => 'Y-m-d'] по ВСЕМ датированным блокам курса
     * @return array{shares: array<string, float>, mechanism: string, degenerate: bool}
     */
    public static function attribute(
        float $amount,
        ?string $columnMonth,
        array $covered,
        array $blockMonths,
        array $blockDates,
        string $createdMonth,
        bool $degenerateGuard = false,
    ): array {
        if ($columnMonth) {
            return ['shares' => [$columnMonth => $amount], 'mechanism' => self::BY_COLUMN, 'degenerate' => false];
        }

        if (empty($covered)) {
            return ['shares' => [$createdMonth => $amount], 'mechanism' => self::BY_CREATED, 'degenerate' => false];
        }

        $degenerate = self::scheduleIsDegenerate($blockDates);

        if ($degenerate && $degenerateGuard) {
            return [
                'shares' => [$createdMonth => $amount],
                'mechanism' => self::BY_DEGENERATE_FALLBACK,
                'degenerate' => true,
            ];
        }

        return [
            'shares' => self::distribute($amount, $covered, $blockMonths, $createdMonth),
            'mechanism' => self::BY_BLOCKS,
            'degenerate' => $degenerate,
        ];
    }

    /**
     * Включён ли сторож вырожденного расписания. Дефолт — ВЫКЛЮЧЕН: мёрж инертен,
     * пока финдир не включит флаг в .env (тот же контур, что
     * REVENUE_REVERSE_UNRECOGNIZED_ON_REFUND в config/revenue.php).
     */
    public static function degenerateGuardEnabled(): bool
    {
        return (bool) config('revenue.recognition_degenerate_schedule_guard', false);
    }
}
