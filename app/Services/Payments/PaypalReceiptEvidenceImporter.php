<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaypalPaymentEvidenceLink;
use App\Models\PaypalReceiptEvidence;
use App\Support\Kopecks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Imports immutable PayPal CSV evidence. Never writes payments or payouts. */
final class PaypalReceiptEvidenceImporter
{
    /** @return array<string,int|string> */
    public function import(string $csvPath, string $mappingPath, bool $apply = false): array
    {
        $csv = file_get_contents($csvPath);
        $mappingRaw = file_get_contents($mappingPath);
        if ($csv === false || $mappingRaw === false) {
            throw new RuntimeException('PayPal CSV or private mapping cannot be read');
        }
        $mapping = json_decode($mappingRaw, true, 512, JSON_THROW_ON_ERROR);
        $assignments = (array) ($mapping['transactions'] ?? []);
        $receipts = $this->receipts($csv);
        $sourceHash = hash('sha256', $csv);
        $result = ['outcome' => $apply ? 'imported' : 'dry_run', 'receipts' => count($receipts), 'mapped_receipts' => 0, 'unmapped_receipts' => 0, 'linked_payments' => 0, 'duplicate_links' => 0, 'conflicts' => 0];

        foreach ($receipts as $transactionId => $row) {
            $paymentIds = array_values(array_unique(array_map('intval', (array) ($assignments[$transactionId] ?? []))));
            if ($paymentIds === []) {
                $result['unmapped_receipts']++;

                continue;
            }
            $result['mapped_receipts']++;
            $payments = Payment::query()->whereIn('id', $paymentIds)->get();
            if ($payments->count() !== count($paymentIds)
                || $payments->contains(fn (Payment $p): bool => $p->status !== 'paid' || (float) $p->foreign_amount <= 0)
                || $payments->pluck('user_id')->unique()->count() !== 1
                || $payments->pluck('course_id')->unique()->count() !== 1
                || $payments->pluck('foreign_currency')->map(fn ($c) => strtoupper((string) $c))->unique()->all() !== [$row['currency']]
                || abs((int) round($payments->sum(fn (Payment $p): float => (float) $p->foreign_amount) * 100) - $row['gross_minor']) > 2) {
                $result['conflicts']++;

                continue;
            }

            $payload = $row + ['source_file_sha256' => $sourceHash];
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $existing = PaypalReceiptEvidence::query()->where('transaction_id', $transactionId)->first();
            if ($existing !== null && ! hash_equals((string) $existing->payload_fingerprint, $fingerprint)) {
                $result['conflicts']++;

                continue;
            }
            foreach ($paymentIds as $paymentId) {
                $link = PaypalPaymentEvidenceLink::query()->where('payment_id', $paymentId)->first();
                if ($link !== null) {
                    if ($existing !== null && (int) $link->receipt_id === (int) $existing->id) {
                        $result['duplicate_links']++;
                    } else {
                        $result['conflicts']++;
                    }

                    continue;
                }
                $result['linked_payments']++;
            }
            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($existing, $payload, $fingerprint, $paymentIds): void {
                $receipt = $existing ?? PaypalReceiptEvidence::query()->create($payload + ['payload_fingerprint' => $fingerprint, 'imported_at' => now()]);
                foreach ($paymentIds as $paymentId) {
                    PaypalPaymentEvidenceLink::query()->firstOrCreate(
                        ['payment_id' => $paymentId],
                        ['receipt_id' => $receipt->id, 'confirmed_by' => auth()->id(), 'created_at' => now()],
                    );
                }
            });
        }

        foreach (array_diff(array_keys($assignments), array_keys($receipts)) as $unknownTransaction) {
            if ((array) $assignments[$unknownTransaction] !== []) {
                $result['conflicts']++;
            }
        }
        if ($result['conflicts'] > 0) {
            $result['outcome'] = 'incomplete';
        }

        return $result;
    }

    /** @return array<string,array<string,mixed>> */
    private function receipts(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));
        $header = array_map('trim', str_getcsv((string) array_shift($lines)));
        $index = array_flip($header);
        foreach (['Date', 'Name', 'Type', 'Status', 'Currency', 'Amount', 'Fees', 'Transaction ID', 'Item Title'] as $required) {
            if (! array_key_exists($required, $index)) {
                throw new RuntimeException("PayPal CSV missing column {$required}");
            }
        }
        $dayFirst = collect($lines)->contains(function (string $line) use ($index): bool {
            $cells = str_getcsv($line);
            $parts = preg_split('#[/.]#', trim((string) ($cells[$index['Date']] ?? ''))) ?: [];

            return count($parts) === 3 && (int) $parts[0] > 12;
        });
        $out = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line);
            $value = fn (string $key): string => trim((string) ($cells[$index[$key]] ?? ''));
            $type = $value('Type');
            $status = mb_strtolower($value('Status'));
            $transactionId = $value('Transaction ID');
            $net = $this->minor($value('Amount'));
            $fee = $this->minor($value('Fees'));
            $gross = $net - $fee;
            if (! in_array($status, ['completed', 'завершено'], true)
                || preg_match('/authorization|hold|reversal|авторизац|удержан|сторн/iu', $type) === 1
                || $transactionId === '' || $net <= 0 || $gross <= 0) {
                continue;
            }
            $row = [
                'transaction_id' => $transactionId,
                'completed_on' => $this->date($value('Date'), $dayFirst),
                'currency' => strtoupper($value('Currency')),
                'gross_minor' => $gross,
                'fee_minor' => $fee,
                'net_minor' => $net,
                'payer_digest' => hash('sha256', mb_strtolower($value('Name'))),
                'item_digest' => hash('sha256', $value('Item Title')),
            ];
            if (isset($out[$transactionId]) && $out[$transactionId] !== $row) {
                throw new RuntimeException("PayPal transaction {$transactionId} is replayed with different data");
            }
            $out[$transactionId] = $row;
        }

        return $out;
    }

    private function minor(string $value): int
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));
        if (str_contains($value, ',') && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }

        return Kopecks::fromDecimal($value);
    }

    private function date(string $raw, bool $dayFirst): string
    {
        $parts = preg_split('#[/.]#', trim(explode(' ', $raw)[0])) ?: [];
        if (count($parts) !== 3) {
            throw new RuntimeException("PayPal date {$raw} is invalid");
        }
        [$a, $b, $year] = array_map('intval', $parts);
        [$day, $month] = $dayFirst ? [$a, $b] : [$b, $a];
        if (! checkdate($month, $day, $year)) {
            throw new RuntimeException("PayPal date {$raw} is invalid");
        }

        return CarbonImmutable::create($year, $month, $day)->toDateString();
    }
}
