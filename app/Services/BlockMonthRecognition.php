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
    public const BY_COLUMN = 'column';                        // salary_recognition_month (ручной override)

    public const BY_BLOCKS = 'blocks';                        // раскладка по месяцам покрытых блоков

    public const BY_CREATED = 'created';                      // платёж без курса/блоков → месяц оплаты

    public const BY_STAMPED_RUN = 'blocks_stamped_run';       // покрытые блоки — штамп бэкофилла → месяц оплаты

    /**
     * ШТАМПОВАННЫЙ ПРОГОН: все блоки, покрытые ЭТИМ платежом, несут одну и ту же
     * дату старта. Это подпись массового бэкофилла (FINDINGS §621 «одинаковая
     * дата у десятков строк — отметка миграции, а не событие жизненного цикла»),
     * а не календарь: у настоящего расписания блоки идут в разные дни.
     *
     * Предикат намеренно ПОБЛОЧНЫЙ, а не покурсовой. Перепись прода 02-09-2026
     * показала, что покурсовой вариант промахивается в обе стороны:
     *   • курс 266 (тот самый дефект, платёж на 36 блоков вперёд) держит 70
     *     датированных блоков на 21 дате — курс «не вырожден», хотя блоки 1..50
     *     стоят одним штампом 2025-03-14, а 51..70 идут настоящим 28-дневным
     *     циклом. Покурсовой сторож на этой строке не сработал бы вовсе;
     *   • курсы 363/364/376 покурсово «вырождены» (2–4 блока в одну дату), но их
     *     платежи ОДНОБЛОЧНЫЕ — там месяц признания однозначен, и сторож задел бы
     *     167 строк на 926 330 ₽ впустую.
     * Тот же штамп 2025-03-14 стоит и на курсах 334/356/357/366 — это отметка
     * миграции, общая для шести курсов, а не шесть совпавших расписаний.
     *
     * Один покрытый блок — не штамп (одна дата тривиально «одинакова»). Хотя бы
     * один покрытый блок без даты — тоже не штамп: раскладка там и так падает на
     * месяц оплаты через distribute().
     *
     * @param  list<int>  $covered  покрытые платежом номера блоков
     * @param  array<int, string>  $blockDates  [block_number => 'Y-m-d'] датированные блоки курса
     */
    public static function coveredRunIsStamped(array $covered, array $blockDates): bool
    {
        if (count($covered) < 2) {
            return false;
        }

        $seen = [];
        foreach ($covered as $n) {
            $d = $blockDates[$n] ?? null;
            if ($d === null || $d === '') {
                return false;
            }
            $seen[$d] = true;
        }

        return count($seen) === 1;
    }

    /**
     * Полная атрибуция месяца признания С НАЗВАННЫМ МЕХАНИЗМОМ (H3951).
     * Возвращает и раскладку, и то, чем она получена, — вызывающий код обязан
     * уметь сказать по каждой строке, признана она колонкой или эвристикой.
     *
     * Порядок ровно тот, что был до H3951 (поведение при $stampedRunGuard=false
     * байт-в-байт прежнее):
     *   1. salary_recognition_month → вся сумма в один месяц (BY_COLUMN);
     *   2. нет покрытых блоков → месяц оплаты (BY_CREATED);
     *   3. иначе раскладка по месяцам блоков (BY_BLOCKS).
     * Флаг $stampedRunGuard (config revenue.recognition_stamped_block_run_guard,
     * дефолт OFF) добавляет шаг 2.5: штампованный прогон блоков → месяц оплаты
     * (BY_STAMPED_RUN). Пока флаг выключен, механизм всё равно репортится как
     * BY_BLOCKS, а признак `stamped` остаётся true — это и есть «named fallback,
     * не тихое смешение»: аудит видит затронутые строки ДО того, как поведение
     * поменяется.
     *
     * Месяц оплаты для штампованного прогона — тоже не истина, а НАЗВАННЫЙ
     * запасной вариант: он лишь перестаёт уносить деньги в закрытый период на
     * полтора года назад. Настоящая атрибуция такого платежа (какие именно блоки
     * оплачены и по каким месяцам их разносить) — решение человека, и до этого
     * решения строка обязана быть видна в аудите как штампованная.
     *
     * @param  list<int>  $covered  покрытые номера блоков
     * @param  array<int, string>  $blockMonths  [block_number => 'Y-m']
     * @param  array<int, string>  $blockDates  [block_number => 'Y-m-d'] по ВСЕМ датированным блокам курса
     * @return array{shares: array<string, float>, mechanism: string, stamped: bool}
     */
    public static function attribute(
        float $amount,
        ?string $columnMonth,
        array $covered,
        array $blockMonths,
        array $blockDates,
        string $createdMonth,
        bool $stampedRunGuard = false,
    ): array {
        if ($columnMonth) {
            return ['shares' => [$columnMonth => $amount], 'mechanism' => self::BY_COLUMN, 'stamped' => false];
        }

        if (empty($covered)) {
            return ['shares' => [$createdMonth => $amount], 'mechanism' => self::BY_CREATED, 'stamped' => false];
        }

        $stamped = self::coveredRunIsStamped($covered, $blockDates);

        if ($stamped && $stampedRunGuard) {
            return [
                'shares' => [$createdMonth => $amount],
                'mechanism' => self::BY_STAMPED_RUN,
                'stamped' => true,
            ];
        }

        return [
            'shares' => self::distribute($amount, $covered, $blockMonths, $createdMonth),
            'mechanism' => self::BY_BLOCKS,
            'stamped' => $stamped,
        ];
    }

    /**
     * Включён ли сторож штампованного прогона. Дефолт — ВЫКЛЮЧЕН: мёрж инертен,
     * пока финдир не включит флаг в .env (тот же контур, что
     * REVENUE_REVERSE_UNRECOGNIZED_ON_REFUND в config/revenue.php).
     */
    public static function stampedRunGuardEnabled(): bool
    {
        return (bool) config('revenue.recognition_stamped_block_run_guard', false);
    }
}
