<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\MoneyBankStatement;
use App\Models\MoneyBankStatementCredit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * H5480 (P3b): идемпотентный append-only импорт зачислений банковской выписки.
 *
 *  - тот же файл (sha256) — импорт уже записан, новых строк ноль;
 *  - пересекающиеся выписки — строки дедуплицируются по row_hash;
 *  - ничего, кроме money_bank_statement* , не пишется: доказательство о
 *    деньгах, а не деньги (D8).
 */
final class BankStatementImporter
{
    public function __construct(private readonly TochkaCreditStatementParser $parser) {}

    /**
     * @return array{statement: MoneyBankStatement, already_imported: bool, parsed: int, inserted: int, duplicates: int, skipped: int, kopecks: int, by_kind: array<string, array{rows: int, kopecks: int}>}
     *
     * @throws StatementFormatError
     */
    public function import(string $path, ?CarbonImmutable $from, ?CarbonImmutable $to, bool $persist = true): array
    {
        $contents = (string) file_get_contents($path);
        $fileHash = hash('sha256', $contents);
        $parsed = $this->parser->parse($contents);
        $rows = $parsed['rows'];

        $dates = array_column($rows, 'value_date');
        sort($dates);
        $coversFrom = $from?->toDateString() ?? ($dates[0] ?? null);
        $coversTo = $to?->toDateString() ?? ($dates === [] ? null : $dates[count($dates) - 1]);
        if ($coversFrom === null || $coversTo === null) {
            throw new StatementFormatError(
                'выписка не содержит ни одной строки зачисления и период не задан: '
                .'передайте --from/--to, иначе покрытый день определить нельзя'
            );
        }
        if ($coversFrom > $coversTo) {
            throw new StatementFormatError("период выписки задом наперёд: {$coversFrom} > {$coversTo}");
        }

        $kopecks = array_sum(array_column($rows, 'amount_kopecks'));
        $byKind = [];
        foreach ($rows as $r) {
            $byKind[$r['kind']]['rows'] = ($byKind[$r['kind']]['rows'] ?? 0) + 1;
            $byKind[$r['kind']]['kopecks'] = ($byKind[$r['kind']]['kopecks'] ?? 0) + (int) $r['amount_kopecks'];
        }
        ksort($byKind);

        $existing = MoneyBankStatement::query()->where('file_hash', $fileHash)->first();
        if ($existing !== null) {
            return [
                'statement' => $existing, 'already_imported' => true, 'parsed' => count($rows),
                'inserted' => 0, 'duplicates' => count($rows), 'skipped' => $parsed['skipped'],
                'kopecks' => (int) $kopecks, 'by_kind' => $byKind,
            ];
        }

        if (! $persist) {
            $statement = new MoneyBankStatement([
                'file_hash' => $fileHash, 'file_name' => basename($path),
                'covers_from' => $coversFrom, 'covers_to' => $coversTo,
                'period_source' => $from !== null && $to !== null ? MoneyBankStatement::PERIOD_EXPLICIT : MoneyBankStatement::PERIOD_DERIVED,
                'rows_imported' => count($rows), 'rows_skipped' => $parsed['skipped'], 'credit_kopecks' => (int) $kopecks,
            ]);

            return [
                'statement' => $statement, 'already_imported' => false, 'parsed' => count($rows),
                'inserted' => count($rows), 'duplicates' => 0, 'skipped' => $parsed['skipped'],
                'kopecks' => (int) $kopecks, 'by_kind' => $byKind,
            ];
        }

        return DB::transaction(function () use ($fileHash, $path, $coversFrom, $coversTo, $from, $to, $rows, $parsed, $kopecks, $byKind): array {
            $known = MoneyBankStatementCredit::query()
                ->whereIn('row_hash', array_column($rows, 'row_hash'))
                ->pluck('row_hash')->all();
            $known = array_flip($known);

            $statement = MoneyBankStatement::query()->create([
                'file_hash' => $fileHash,
                'file_name' => basename($path),
                'covers_from' => $coversFrom,
                'covers_to' => $coversTo,
                'period_source' => $from !== null && $to !== null ? MoneyBankStatement::PERIOD_EXPLICIT : MoneyBankStatement::PERIOD_DERIVED,
                'rows_imported' => 0,
                'rows_skipped' => $parsed['skipped'],
                'credit_kopecks' => 0,
            ]);

            $inserted = 0;
            $now = now();
            foreach (array_chunk($rows, 500) as $chunk) {
                $payload = [];
                foreach ($chunk as $r) {
                    if (isset($known[$r['row_hash']])) {
                        continue;
                    }
                    $known[$r['row_hash']] = true; // дубли внутри одного файла
                    $payload[] = $r + ['statement_id' => $statement->id, 'created_at' => $now];
                }
                if ($payload !== []) {
                    MoneyBankStatementCredit::query()->insert($payload);
                    $inserted += count($payload);
                }
            }

            // Счётчики файла пишутся один раз, до появления его строк в отчётах.
            $statement->forceFill(['rows_imported' => $inserted, 'credit_kopecks' => (int) $kopecks])->save();

            return [
                'statement' => $statement, 'already_imported' => false, 'parsed' => count($rows),
                'inserted' => $inserted, 'duplicates' => count($rows) - $inserted, 'skipped' => $parsed['skipped'],
                'kopecks' => (int) $kopecks, 'by_kind' => $byKind,
            ];
        });
    }
}
