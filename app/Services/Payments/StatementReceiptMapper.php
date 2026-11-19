<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * «Неразобранные поступления» цикла (H6705, H5554 gap [1]): строки зачислений
 * выписки Точки, у которых нет связи с оплатой LMS. Только чтение — источник
 * уже импортирован (bank_statement_imports / bank_statement_credits, H5480),
 * здесь он впервые читается отчётом, а не сверкой.
 *
 * Канон сверки соблюдён (H5480/H4645): построчной связки студент↔строка
 * НЕ существует. QR-расчёты и дневные агрегаты эквайринга контролируются
 * дневными тождествами BankStatementControl — здесь они «неразобранными» НЕ
 * считаются и идут справочно (day_controlled). Непривязанные строки:
 *  - kind=transfer без «Заказ №N» либо с заказом, которого нет среди оплаченных
 *    Payment (номер заказа = id платежа — так его находит вебхук Tochka);
 *  - kind=other — назначение не распознано (класс unknown_purpose сверки).
 *
 * Числа рендерятся только при ПОЛНОМ покрытии цикла импортами (объединение
 * covers_from..covers_to закрывает каждый день цикла). Частичное покрытие —
 * статус и список дыр, а не «ноль»: молчать про то, чего не видели, честнее.
 */
final class StatementReceiptMapper
{
    /**
     * @return array<string, mixed>
     */
    public function cycle(string $period): array
    {
        $start = Carbon::parse($period.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        $base = [
            'period' => $period,
            'window' => [$start->toDateString(), $end->toDateString()],
        ];

        if (! Schema::hasTable('bank_statement_imports')) {
            return $base + [
                'status' => 'source_absent',
                'note' => 'bank_statement_imports table absent — credit import not migrated',
            ];
        }

        $imports = BankStatementImport::query()
            ->where('covers_from', '<=', $end)
            ->where('covers_to', '>=', $start)
            ->orderBy('covers_from')
            ->get(['id', 'covers_from', 'covers_to', 'file_sha256']);

        if ($imports->isEmpty()) {
            return $base + [
                'status' => 'no_coverage',
                'note' => 'no imported statement overlaps '.$period,
                'imports' => [],
            ];
        }

        $gaps = $this->uncoveredDays($start, $end, $imports);
        if ($gaps !== []) {
            return $base + [
                'status' => 'partial_coverage',
                'note' => count($gaps).' day(s) of '.$period.' not covered by any import — sums are withheld, not zero',
                'imports' => $this->importsList($imports),
                'uncovered_days' => count($gaps),
                'first_uncovered' => $gaps[0],
                'last_uncovered' => $gaps[count($gaps) - 1],
            ];
        }

        $credits = BankStatementCredit::query()
            ->whereBetween('booked_on', [$start->toDateString(), $end->toDateString()])
            ->get(['kind', 'order_ref', 'amount_kopecks']);

        // Маппинг transfer-строк: заказ = id оплаченного Payment (одним запросом).
        $orderRefs = $credits->pluck('order_ref')->filter()->unique()->values()->all();
        $paidOrderIds = $orderRefs === []
            ? collect()
            : Payment::query()
                ->whereIn('id', $orderRefs)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->pluck('id');

        $byKind = [];
        $unmappedRows = 0;
        $unmappedKopecks = 0;
        $unmappedTransferRows = 0;
        $unmappedTransferKopecks = 0;
        $unmappedOtherRows = 0;
        $unmappedOtherKopecks = 0;
        $mappedTransferRows = 0;
        $mappedTransferKopecks = 0;
        $dayControlledRows = 0;
        $dayControlledKopecks = 0;
        $totalRows = 0;
        $totalKopecks = 0;

        foreach ($credits as $credit) {
            $rows = 1;
            $kop = (int) $credit->amount_kopecks;
            $totalRows += $rows;
            $totalKopecks += $kop;
            $kind = (string) $credit->kind;
            $byKind[$kind]['rows'] = ($byKind[$kind]['rows'] ?? 0) + $rows;
            $byKind[$kind]['amount'] = round(($byKind[$kind]['amount'] ?? 0.0) + $kop / 100, 2);

            if ($kind === BankStatementCredit::KIND_QR || $kind === BankStatementCredit::KIND_ACQUIRING) {
                // H4645: построчного маппинга не существует, контроль — дневной.
                $dayControlledRows += $rows;
                $dayControlledKopecks += $kop;

                continue;
            }

            $mapped = $kind === BankStatementCredit::KIND_TRANSFER
                && $credit->order_ref !== null
                && $paidOrderIds->contains((int) $credit->order_ref);

            if ($mapped) {
                $mappedTransferRows += $rows;
                $mappedTransferKopecks += $kop;

                continue;
            }

            $unmappedRows += $rows;
            $unmappedKopecks += $kop;
            if ($kind === BankStatementCredit::KIND_TRANSFER) {
                $unmappedTransferRows += $rows;
                $unmappedTransferKopecks += $kop;
            } else {
                $unmappedOtherRows += $rows;
                $unmappedOtherKopecks += $kop;
            }
        }
        ksort($byKind);

        return $base + [
            'status' => 'ok',
            'imports' => $this->importsList($imports),
            'credits' => ['rows' => $totalRows, 'amount' => round($totalKopecks / 100, 2)],
            'by_kind' => $byKind,
            'unmapped' => [
                'rows' => $unmappedRows,
                'amount' => round($unmappedKopecks / 100, 2),
                'transfer_rows' => $unmappedTransferRows,
                'transfer_amount' => round($unmappedTransferKopecks / 100, 2),
                'other_rows' => $unmappedOtherRows,
                'other_amount' => round($unmappedOtherKopecks / 100, 2),
            ],
            'mapped_transfer' => ['rows' => $mappedTransferRows, 'amount' => round($mappedTransferKopecks / 100, 2)],
            'day_controlled' => [
                'note' => 'QR settlements and acquiring aggregates have no line mapping by design (H4645); day-level recon governs them',
                'rows' => $dayControlledRows,
                'amount' => round($dayControlledKopecks / 100, 2),
            ],
        ];
    }

    /**
     * Дни цикла, не покрытые ни одним импортом (объединение интервалов).
     *
     * @param  Collection<int, BankStatementImport>  $imports
     * @return list<string> дат 'Y-m-d' по возрастанию
     */
    private function uncoveredDays(Carbon $start, Carbon $end, Collection $imports): array
    {
        $intervals = $imports
            ->map(fn (BankStatementImport $import): array => [
                Carbon::parse($import->covers_from)->startOfDay(),
                Carbon::parse($import->covers_to)->startOfDay(),
            ])
            ->sortBy([['0', 'asc'], ['1', 'desc']])
            ->values();

        $gaps = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $covered = false;
            foreach ($intervals as [$from, $to]) {
                if ($day->betweenIncluded($from, $to)) {
                    $covered = true;

                    break;
                }
            }
            if (! $covered) {
                $gaps[] = $day->toDateString();
            }
        }

        return $gaps;
    }

    /**
     * @param  Collection<int, BankStatementImport>  $imports
     * @return list<array<string, mixed>>
     */
    private function importsList(Collection $imports): array
    {
        return $imports
            ->map(fn (BankStatementImport $import): array => [
                'import_id' => (int) $import->id,
                'covers_from' => $import->covers_from?->toDateString(),
                'covers_to' => $import->covers_to?->toDateString(),
                'file_sha256' => (string) $import->file_sha256,
            ])
            ->all();
    }
}
