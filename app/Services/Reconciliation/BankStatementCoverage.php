<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\BankStatementImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * H7267 (остаток H5445/H5480): покрытие выписки по дням — ТОЛЬКО ЧТЕНИЕ.
 *
 * Покрытие берётся исключительно из `bank_statement_imports.covers_from` /
 * `covers_to`, то есть из периода, который назвал человек при импорте. Даты
 * строк выписки для этого не годятся: день без зачислений выглядел бы
 * непокрытым, а день с одной строкой — покрытым.
 *
 * День покрыт, только если ОДИН импорт покрывает его ЦЕЛИКОМ (00:00:00 …
 * 23:59:59). Частичный период — не покрытие: это то же правило, по которому
 * `BankStatementControl::source()` оставляет источник `missing`.
 *
 * Отчёт ничего не пишет: ни денег, ни доступа, ни флагов, ни таблиц сверки.
 */
final class BankStatementCoverage
{
    /**
     * @return array{
     *     as_of: string,
     *     window: array{from: string, to: string, days: int},
     *     readable: array{table: bool, flag: string, enabled: bool},
     *     imports: list<array<string, mixed>>,
     *     days: list<array{date: string, covered: bool, import_id: int|null}>,
     *     months: list<array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('coverage: window ends before it starts');
        }

        $imports = $this->imports($from, $to);

        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $cover = $this->coveringImport($imports, $day);
            $days[] = [
                'date' => $day->toDateString(),
                'covered' => $cover !== null,
                'import_id' => $cover === null ? null : (int) $cover['id'],
            ];
        }

        $months = $this->byMonth($days);
        $coveredDates = array_values(array_map(
            static fn (array $d): string => $d['date'],
            array_filter($days, static fn (array $d): bool => $d['covered']),
        ));
        $missingDates = array_values(array_map(
            static fn (array $d): string => $d['date'],
            array_filter($days, static fn (array $d): bool => ! $d['covered']),
        ));

        return [
            'as_of' => CarbonImmutable::now((string) config('app.timezone'))->toDateTimeString(),
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => count($days),
            ],
            'readable' => [
                'table' => Schema::hasTable('bank_statement_imports'),
                'flag' => 'features.money_bank_statement_credits',
                'enabled' => (bool) config('features.money_bank_statement_credits', false),
            ],
            'imports' => $imports,
            'days' => $days,
            'months' => $months,
            'totals' => [
                'days' => count($days),
                'covered' => count($coveredDates),
                'missing' => count($missingDates),
                'coverage_pct' => $this->pct(count($coveredDates), count($days)),
                'covered_ranges' => $this->ranges($coveredDates),
                'missing_ranges' => $this->ranges($missingDates),
            ],
        ];
    }

    /**
     * Импорты, которые вообще могут покрыть окно [from; to] (включительно).
     * Пустой список, если таблицы нет или импортов не было.
     *
     * @return list<array<string, mixed>>
     */
    public function imports(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('bank_statement_imports')) {
            return [];
        }

        return BankStatementImport::query()
            ->where('covers_from', '<=', $to->toDateTimeString())
            ->where('covers_to', '>=', $from->toDateTimeString())
            ->orderBy('id')
            ->get()
            ->map(fn (BankStatementImport $i): array => [
                'id' => (int) $i->id,
                'provider' => (string) $i->provider,
                'file_sha256' => (string) $i->file_sha256,
                'covers_from' => $i->covers_from->toDateString(),
                'covers_to' => $i->covers_to->toDateString(),
                'covers_from_at' => $i->covers_from->toDateTimeString(),
                'covers_to_at' => $i->covers_to->toDateTimeString(),
                'rows_total' => (int) $i->rows_total,
                'rows_imported' => (int) $i->rows_imported,
                'rows_duplicate' => (int) $i->rows_duplicate,
                'rows_skipped' => (int) $i->rows_skipped,
                'credited_kopecks' => (int) $i->credited_kopecks,
                'imported_at' => $i->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /** Самый ранний день, который когда-либо покрывал импорт (для окна по умолчанию). */
    public function earliestCoveredFrom(): ?CarbonImmutable
    {
        if (! Schema::hasTable('bank_statement_imports')) {
            return null;
        }

        $min = BankStatementImport::query()->min('covers_from');
        if ($min === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $min)
            ->setTimezone((string) config('app.timezone'))
            ->startOfDay();
    }

    /**
     * @param  list<array<string, mixed>>  $imports
     * @return array<string, mixed>|null
     */
    private function coveringImport(array $imports, CarbonImmutable $day): ?array
    {
        $dayStart = $day->startOfDay()->toDateTimeString();
        // Последняя секунда дня: в MariaDB datetime хранится без микросекунд,
        // поэтому граница — 23:59:59, как в BankStatementControl.
        $dayEnd = $day->addDay()->startOfDay()->subSecond()->toDateTimeString();

        foreach ($imports as $import) {
            if ($import['covers_from_at'] <= $dayStart && $import['covers_to_at'] >= $dayEnd) {
                return $import;
            }
        }

        return null;
    }

    /**
     * @param  list<array{date: string, covered: bool, import_id: int|null}>  $days
     * @return list<array<string, mixed>>
     */
    private function byMonth(array $days): array
    {
        $months = [];

        foreach ($days as $day) {
            $key = substr($day['date'], 0, 7);
            $months[$key] ??= [
                'month' => $key,
                'days' => 0,
                'covered' => 0,
                'missing' => 0,
                'covered_dates' => [],
                'missing_dates' => [],
            ];
            $months[$key]['days']++;
            if ($day['covered']) {
                $months[$key]['covered']++;
                $months[$key]['covered_dates'][] = $day['date'];
            } else {
                $months[$key]['missing']++;
                $months[$key]['missing_dates'][] = $day['date'];
            }
        }

        $out = [];
        foreach ($months as $m) {
            $out[] = [
                'month' => $m['month'],
                'days' => $m['days'],
                'covered' => $m['covered'],
                'missing' => $m['missing'],
                'coverage_pct' => $this->pct($m['covered'], $m['days']),
                'covered_ranges' => $this->ranges($m['covered_dates']),
                'missing_ranges' => $this->ranges($m['missing_dates']),
            ];
        }

        return $out;
    }

    /** @param  list<string>  $dates  по возрастанию @return list<string> */
    private function ranges(array $dates): array
    {
        $out = [];
        $start = null;
        $prev = null;

        foreach ($dates as $date) {
            $cur = CarbonImmutable::parse($date)->startOfDay();
            if ($start === null || $prev === null) {
                $start = $cur;
                $prev = $cur;

                continue;
            }
            if ($cur->equalTo($prev->addDay())) {
                $prev = $cur;

                continue;
            }
            $out[] = $this->range($start, $prev);
            $start = $cur;
            $prev = $cur;
        }

        if ($start !== null && $prev !== null) {
            $out[] = $this->range($start, $prev);
        }

        return $out;
    }

    private function range(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->equalTo($to)) {
            return $from->toDateString();
        }

        return $from->toDateString().'..'.$to->toDateString();
    }

    private function pct(int $covered, int $days): float
    {
        return $days > 0 ? round($covered * 100 / $days, 1) : 0.0;
    }
}
