<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * H5480: идемпотентный импорт зачислений выписки.
 *
 * 1. Повтор ТОГО ЖЕ файла (sha256) возвращает прежний импорт и не пишет ничего.
 * 2. Повтор строки (row_hash) в другом файле — пропуск: append-only, одна
 *    строка выписки существует ровно один раз.
 * 3. Период покрытия задаёт человек: он знает, что выбрал при выгрузке.
 *    Выводить его из min/max дат строк нельзя — день без зачислений тогда
 *    выглядел бы непокрытым (или, хуже, покрытым наполовину).
 */
final class BankStatementImporter
{
    public function __construct(private readonly BankStatementParser $parser) {}

    /**
     * @return array{import: ?BankStatementImport, outcome: string, total: int, imported: int, duplicate: int, skipped: int, kopecks: int, by_kind: array<string, array{rows: int, kopecks: int}>}
     */
    public function importFile(string $path, CarbonImmutable $coversFrom, CarbonImmutable $coversTo, bool $dryRun = false, ?int $actorId = null): array
    {
        if ($coversTo->lessThanOrEqualTo($coversFrom)) {
            throw new StatementFormatError('statement: covered period ends before it starts');
        }

        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new StatementFormatError("statement: {$path} is unreadable");
        }

        $parsed = $this->parser->parseFile($path);
        $byKind = $this->byKind($parsed['rows']);

        $existing = BankStatementImport::query()->where('file_sha256', $sha)->first();
        if ($existing !== null) {
            return [
                'import' => $existing,
                'outcome' => 'already_imported',
                'total' => (int) $existing->rows_total,
                'imported' => 0,
                'duplicate' => (int) $existing->rows_imported + (int) $existing->rows_duplicate,
                'skipped' => (int) $existing->rows_skipped,
                'kopecks' => (int) $existing->credited_kopecks,
                'by_kind' => $byKind,
            ];
        }

        if ($dryRun) {
            return [
                'import' => null,
                'outcome' => 'dry_run',
                'total' => $parsed['total'],
                'imported' => count($parsed['rows']),
                'duplicate' => 0,
                'skipped' => $parsed['skipped'],
                'kopecks' => array_sum(array_column($parsed['rows'], 'amount_kopecks')),
                'by_kind' => $byKind,
            ];
        }

        return DB::transaction(function () use ($sha, $path, $coversFrom, $coversTo, $parsed, $byKind, $actorId): array {
            $known = BankStatementCredit::query()
                ->whereIn('row_hash', array_column($parsed['rows'], 'row_hash'))
                ->pluck('row_hash')
                ->flip();

            // Строка импорта неизменяема (триггер запрещает и UPDATE, и DELETE),
            // поэтому счётчики считаем ДО её создания: дубли внутри файла тоже.
            $fresh = [];
            $duplicate = 0;
            $kopecks = 0;
            foreach ($parsed['rows'] as $row) {
                if ($known->has($row['row_hash'])) {
                    $duplicate++;

                    continue;
                }
                $known->put($row['row_hash'], true);
                $fresh[] = $row;
                $kopecks += (int) $row['amount_kopecks'];
            }
            $imported = count($fresh);

            $import = BankStatementImport::query()->create([
                'file_sha256' => $sha,
                'file_name' => mb_substr(basename($path), 0, 191),
                'provider' => 'tochka',
                'covers_from' => $coversFrom->toDateTimeString(),
                'covers_to' => $coversTo->toDateTimeString(),
                'rows_total' => $parsed['total'],
                'rows_imported' => $imported,
                'rows_duplicate' => $duplicate,
                'rows_skipped' => $parsed['skipped'],
                'credited_kopecks' => $kopecks,
                'imported_by' => $actorId,
            ]);

            foreach ($fresh as $row) {
                BankStatementCredit::query()->create($row + ['import_id' => $import->id]);
            }

            return [
                'import' => $import,
                'outcome' => 'imported',
                'total' => $parsed['total'],
                'imported' => $imported,
                'duplicate' => $duplicate,
                'skipped' => $parsed['skipped'],
                'kopecks' => $kopecks,
                'by_kind' => $byKind,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{rows: int, kopecks: int}>
     */
    private function byKind(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = (string) $r['kind'];
            $out[$k]['rows'] = ($out[$k]['rows'] ?? 0) + 1;
            $out[$k]['kopecks'] = ($out[$k]['kopecks'] ?? 0) + (int) $r['amount_kopecks'];
        }
        ksort($out);

        return $out;
    }
}
