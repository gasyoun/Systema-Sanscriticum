<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use App\Models\MoneyReconException;
use App\Models\Payment;
use App\Support\Kopecks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * H5480 (P3): источник `bank_statement` и ДНЕВНОЙ агрегатный контроль.
 *
 * Построчного сопоставления не существует и не будет: выписка не несёт
 * идентификатора студента (H4645 — 1051/1052 входящих строк называют
 * плательщиком банк; 939 пер-QR расчётов, 111 дневных агрегатов эквайринга за
 * вычетом комиссии). Поэтому сверяются СУММЫ ДНЯ:
 *
 *  1. QR-расчёты дня vs оплаты канала bank_acquiring окна [день − лаг; день]
 *     (расчёт приходит в тот же день или позже, лаг настраиваемый, T+1);
 *  2. агрегат эквайринга дня vs те же оплаты за вычетом комиссии: банк
 *     присылает нетто, поэтому проверяется не равенство, а что подразумеваемая
 *     комиссия лежит в [0; max_fee_bps].
 *
 * Расхождение — одно исключение currency_amount_mismatch на контроль (не по
 * строке), доказательство — хэши строк выписки. Нераспознанные зачисления дня
 * дают одно unknown_purpose. Сверка ничего не чинит и ничего не закрывает.
 *
 * Источник «present» — только если какой-то ОДИН импорт покрывает день
 * целиком; частичная выписка остаётся missing, а не «ноль».
 */
final class BankStatementControl
{
    public const SOURCE = 'bank_statement';

    /** @return array<string, mixed> статус источника для EvidenceCollector */
    public function source(?CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('bank_statement_imports')) {
            return ['status' => 'missing', 'note' => 'bank_statement_imports table absent — credit import not migrated'];
        }
        if (! (bool) config('features.money_bank_statement_credits', false)) {
            return ['status' => 'missing', 'note' => 'MONEY_BANK_STATEMENT_CREDITS is off — credit statements are not read as evidence yet'];
        }
        if ($from === null) {
            return ['status' => 'missing', 'note' => 'all-history window: the credit statement covers days, not the whole history'];
        }

        $cover = $this->covering($from, $to);
        if ($cover === null) {
            return ['status' => 'missing', 'note' => 'no imported statement covers '.$from->toDateString().' in full (partial period is not coverage)'];
        }

        $day = $this->dayTotals($from);

        return [
            'status' => 'present',
            'import_id' => (int) $cover->id,
            'file_sha256' => (string) $cover->file_sha256,
            'covers' => $cover->covers_from->toDateTimeString().'..'.$cover->covers_to->toDateTimeString(),
            'credits' => $day,
        ];
    }

    /**
     * Findings дневного контроля. Пустой список, если источника нет: молчать
     * про то, чего не видели, честнее, чем открыть исключение «ноль ≠ сумма».
     *
     * @param  array<string, mixed>  $source  результат self::source()
     * @return list<array<string, mixed>>
     */
    public function findings(CarbonImmutable $day, array $source): array
    {
        if (($source['status'] ?? null) !== 'present') {
            return [];
        }

        $lag = max(0, (int) config('money_recon.settlement_lag_days', 1));
        $tolerance = max(0, (int) config('money_recon.aggregate_tolerance_kopecks', 0));
        $maxFeeBps = max(0, (int) config('money_recon.acquiring_max_fee_bps', 350));

        $credits = $this->creditsOfDay($day);
        $acquiringPayments = $this->acquiringPaymentsKopecks($day->subDays($lag), $day->addDay());

        $out = [];

        // 1. Пер-QR расчёты дня против оплат эквайринга окна.
        $qr = $this->bucket($credits, BankStatementCredit::KIND_QR);
        $delta = $qr['kopecks'] - $acquiringPayments;
        if ($qr['rows'] > 0 && abs($delta) > $tolerance) {
            $out[] = [
                'type' => MoneyReconException::CURRENCY_AMOUNT_MISMATCH,
                'source' => self::SOURCE,
                'source_ref' => 'statement:qr:'.$day->toDateString(),
                'amount_kopecks' => $delta,
                'currency' => 'RUB',
                'evidence' => [
                    'detail' => 'QR settlements of the day differ from bank_acquiring payments',
                    'business_date' => $day->toDateString(),
                    'control' => 'qr_settlements_vs_payments',
                    'settlement_lag_days' => $lag,
                    'statement_kopecks' => $qr['kopecks'],
                    'payments_kopecks' => $acquiringPayments,
                    'delta_kopecks' => $delta,
                    'row_hashes' => $qr['hashes'],
                ],
            ];
        }

        // 2. Дневной агрегат эквайринга: банк присылает НЕТТО, поэтому
        //    проверяется подразумеваемая комиссия, а не равенство.
        $agg = $this->bucket($credits, BankStatementCredit::KIND_ACQUIRING);
        if ($agg['rows'] > 0) {
            $impliedFee = $acquiringPayments - $agg['kopecks'];
            $bps = $acquiringPayments > 0 ? (int) round($impliedFee * 10000 / $acquiringPayments) : null;
            if ($bps === null || $bps < 0 || $bps > $maxFeeBps) {
                $out[] = [
                    'type' => MoneyReconException::CURRENCY_AMOUNT_MISMATCH,
                    'source' => self::SOURCE,
                    'source_ref' => 'statement:acquiring:'.$day->toDateString(),
                    'amount_kopecks' => $impliedFee,
                    'currency' => 'RUB',
                    'evidence' => [
                        'detail' => 'card-acquiring daily aggregate is not the payments of the window net of a plausible fee',
                        'business_date' => $day->toDateString(),
                        'control' => 'acquiring_aggregate_vs_payments_net_of_fee',
                        'settlement_lag_days' => $lag,
                        'statement_kopecks' => $agg['kopecks'],
                        'payments_kopecks' => $acquiringPayments,
                        'implied_fee_kopecks' => $impliedFee,
                        'implied_fee_bps' => $bps,
                        'max_fee_bps' => $maxFeeBps,
                        'row_hashes' => $agg['hashes'],
                    ],
                ];
            }
        }

        // 3. Зачисления, назначение которых не опознано ни как QR, ни как
        //    эквайринг, ни как «Заказ №N»: одно исключение на день.
        $other = $this->bucket($credits, BankStatementCredit::KIND_OTHER);
        if ($other['rows'] > 0) {
            $out[] = [
                'type' => MoneyReconException::UNKNOWN_PURPOSE,
                'source' => self::SOURCE,
                'source_ref' => 'statement:unclassified:'.$day->toDateString(),
                'amount_kopecks' => $other['kopecks'],
                'currency' => 'RUB',
                'evidence' => [
                    'detail' => 'credits whose purpose carries neither a QR id, an acquiring contract nor «Заказ №N»',
                    'business_date' => $day->toDateString(),
                    'control' => 'unclassified_credits',
                    'rows' => $other['rows'],
                    'kopecks' => $other['kopecks'],
                    'row_hashes' => $other['hashes'],
                ],
            ];
        }

        return $out;
    }

    /** @return array<string, array{rows: int, kopecks: int}> */
    public function dayTotals(CarbonImmutable $day): array
    {
        $out = [];
        foreach ($this->creditsOfDay($day) as $row) {
            $k = (string) $row->kind;
            $out[$k]['rows'] = ($out[$k]['rows'] ?? 0) + 1;
            $out[$k]['kopecks'] = ($out[$k]['kopecks'] ?? 0) + (int) $row->amount_kopecks;
        }
        ksort($out);

        return $out;
    }

    /** Импорт, чей период покрывает окно [from; to) ЦЕЛИКОМ. */
    private function covering(CarbonImmutable $from, CarbonImmutable $to): ?BankStatementImport
    {
        return BankStatementImport::query()
            ->where('covers_from', '<=', $from->toDateTimeString())
            ->where('covers_to', '>=', $to->subSecond()->toDateTimeString())
            ->orderBy('id')
            ->first();
    }

    /** @return Collection<int, BankStatementCredit> */
    private function creditsOfDay(CarbonImmutable $day)
    {
        return BankStatementCredit::query()
            ->whereDate('booked_on', $day->toDateString())
            ->orderBy('row_hash')
            ->get();
    }

    /**
     * @param  Collection<int, BankStatementCredit>  $credits
     * @return array{rows: int, kopecks: int, hashes: list<string>}
     */
    private function bucket($credits, string $kind): array
    {
        $rows = $credits->where('kind', $kind);

        return [
            'rows' => $rows->count(),
            'kopecks' => (int) $rows->sum('amount_kopecks'),
            'hashes' => array_values($rows->pluck('row_hash')->all()),
        ];
    }

    /** Оплаты канала bank_acquiring окна [from; to) — брутто, в копейках. */
    private function acquiringPaymentsKopecks(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $sum = 0;
        $q = Payment::query()
            ->whereNull('refund_of_payment_id')
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNotIn('tariff', ['Расход', 'salary_payout'])
            ->whereRaw('COALESCE(first_paid_at, created_at) >= ?', [$from->toDateTimeString()])
            ->whereRaw('COALESCE(first_paid_at, created_at) < ?', [$to->toDateTimeString()])
            ->orderBy('id');

        foreach ($q->lazyById(500) as $p) {
            /** @var Payment $p */
            if ($this->isAcquiring($p)) {
                $sum += Kopecks::fromDecimal((string) $p->amount);
            }
        }

        return $sum;
    }

    /** Тот же выбор канала, что в EvidenceCollector: касса Точки, не PayPal/ручное/преподаватель. */
    private function isAcquiring(Payment $p): bool
    {
        if ($p->received_account === Payment::RECEIVED_TEACHER || $p->provider === Payment::PROVIDER_TEACHER_TRANSFER) {
            return false;
        }

        return ! in_array($p->provider, [
            Payment::PROVIDER_PAYPAL,
            Payment::PROVIDER_PAYPAL_SUBSCRIPTION,
            Payment::PROVIDER_INVOICE,
            Payment::PROVIDER_BANK_SEPA,
        ], true);
    }
}
