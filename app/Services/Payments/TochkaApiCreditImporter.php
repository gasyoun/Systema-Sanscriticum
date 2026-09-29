<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use App\Support\Kopecks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Imports Booked API credits into the existing append-only bank source. */
final class TochkaApiCreditImporter
{
    /** @return array{outcome:string,total:int,imported:int,duplicate:int,skipped:int,kopecks:int} */
    public function importPayload(array $payload, CarbonImmutable $from, CarbonImmutable $to, bool $apply = false): array
    {
        $rows = [];
        $skipped = 0;
        $total = 0;
        foreach ($this->transactions($payload) as $tx) {
            if (($tx['creditDebitIndicator'] ?? null) !== 'Credit' || ($tx['status'] ?? null) !== 'Booked') {
                continue;
            }
            $total++;
            $row = $this->normalize($tx);
            if ($row === null) {
                $skipped++;
            } else {
                $rows[] = $row;
            }
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $existingImport = BankStatementImport::query()->where('file_sha256', $hash)->first();
        if ($existingImport !== null) {
            return ['outcome' => 'already_imported', 'total' => (int) $existingImport->rows_total, 'imported' => 0, 'duplicate' => (int) $existingImport->rows_imported + (int) $existingImport->rows_duplicate, 'skipped' => (int) $existingImport->rows_skipped, 'kopecks' => (int) $existingImport->credited_kopecks];
        }
        $known = BankStatementCredit::query()->whereIn('row_hash', array_column($rows, 'row_hash'))->pluck('row_hash')->flip();
        $fresh = [];
        $duplicate = 0;
        foreach ($rows as $row) {
            if ($known->has($row['row_hash'])) {
                $duplicate++;

                continue;
            }
            $known->put($row['row_hash'], true);
            $fresh[] = $row;
        }
        $kopecks = array_sum(array_column($fresh, 'amount_kopecks'));
        if (! $apply) {
            return ['outcome' => $skipped > 0 ? 'incomplete' : 'dry_run', 'total' => $total, 'imported' => count($fresh), 'duplicate' => $duplicate, 'skipped' => $skipped, 'kopecks' => $kopecks];
        }

        return DB::transaction(function () use ($hash, $from, $to, $total, $fresh, $duplicate, $skipped, $kopecks): array {
            $import = BankStatementImport::query()->create([
                'file_sha256' => $hash,
                'file_name' => 'tochka-open-banking-api.json',
                'provider' => 'tochka_api',
                'covers_from' => $from->startOfDay(),
                'covers_to' => $to->endOfDay(),
                'rows_total' => $total,
                'rows_imported' => count($fresh),
                'rows_duplicate' => $duplicate,
                'rows_skipped' => $skipped,
                'credited_kopecks' => $kopecks,
            ]);
            foreach ($fresh as $row) {
                BankStatementCredit::query()->create($row + ['import_id' => $import->id]);
            }

            return ['outcome' => $skipped > 0 ? 'incomplete' : 'imported', 'total' => $total, 'imported' => count($fresh), 'duplicate' => $duplicate, 'skipped' => $skipped, 'kopecks' => $kopecks];
        });
    }

    /** @return list<array<string,mixed>> */
    private function transactions(array $payload): array
    {
        $statements = isset($payload['statements']) && is_array($payload['statements']) ? $payload['statements'] : [['data' => $payload]];
        $out = [];
        foreach ($statements as $wrapper) {
            $data = is_array($wrapper['data'] ?? null) ? $wrapper['data'] : $wrapper;
            $statement = data_get($data, 'Data.Statement', []);
            foreach (array_is_list($statement) ? $statement : [$statement] as $row) {
                foreach ((array) ($row['Transaction'] ?? []) as $tx) {
                    if (is_array($tx)) {
                        $out[] = $tx;
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function normalize(array $tx): ?array
    {
        $date = substr((string) ($tx['documentProcessDate'] ?? ''), 0, 10);
        $amount = data_get($tx, 'Amount.amount');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! is_numeric($amount)) {
            return null;
        }
        $kopecks = Kopecks::fromDecimal((string) $amount);
        $decimal = number_format($kopecks / 100, 2, '.', '');
        $doc = (string) ($tx['documentNumber'] ?? '');
        $purpose = (string) ($tx['description'] ?? '');
        preg_match('/Заказ\s*№\s*(\d+)/u', $purpose, $order);
        preg_match('/QR коду ID\s+([A-Za-z0-9]+)/u', $purpose, $qr);
        $kind = isset($qr[1]) ? BankStatementCredit::KIND_QR
            : (preg_match('/эквайринг|обслуживании держателей платежных карт/iu', $purpose) === 1 ? BankStatementCredit::KIND_ACQUIRING
                : (isset($order[1]) ? BankStatementCredit::KIND_TRANSFER : BankStatementCredit::KIND_OTHER));

        return [
            'row_hash' => hash('sha256', $doc.'|'.$date.'|'.$decimal.'|'.$purpose),
            'booked_on' => $date,
            'amount_kopecks' => $kopecks,
            'currency' => strtoupper((string) data_get($tx, 'Amount.currency', 'RUB')),
            'kind' => $kind,
            'doc_no' => $doc !== '' ? mb_substr($doc, 0, 64) : null,
            'qr_id' => $qr[1] ?? null,
            'order_ref' => isset($order[1]) ? (int) $order[1] : null,
            'purpose_digest' => hash('sha256', $purpose),
        ];
    }
}
