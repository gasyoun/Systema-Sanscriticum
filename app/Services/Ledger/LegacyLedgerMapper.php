<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\MoneyMovement;
use App\Models\Payment;
use App\Support\Kopecks;
use Illuminate\Support\Carbon;

/**
 * H5443 (P1): чистое отображение легаси-платежей на факты денежного ядра —
 * ничего не пишет. Основа отчёта бэкфилла; настоящий перенос остатков и
 * доказательств — P4 (H5446), здесь только план и аномалии.
 *
 * Семья = исходный платёж + его связанные возвраты (refund_of_payment_id).
 * Все суммы переводятся из decimal-строки БД в копейки без float.
 */
final class LegacyLedgerMapper
{
    public const CAT_BLOCK = 'receipt_block';

    public const CAT_DEPOSIT = 'receipt_deposit';

    public const CAT_TRIAL = 'receipt_trial';

    public const CAT_UNALLOCATED = 'receipt_unallocated';

    public const CAT_DIRECT = 'direct_teacher_receipt';

    public const CAT_REFUND = 'refund_linked';

    public const CAT_UNLINKED_OUTFLOW = 'unlinked_outflow';

    public const CAT_PAYOUT_P2 = 'teacher_payout_deferred_p2';

    public const CAT_NONPOSITIVE = 'nonpositive_receipt';

    public const CAT_NO_STUDENT = 'receipt_without_student';

    public const CAT_ORPHAN_REFUND = 'refund_source_not_a_receipt';

    /** @var array<string, int> evidence_key → первый платёж, который его потребил */
    private array $evidenceSeen = [];

    /**
     * @return iterable<int, array<string, mixed>> план по каждой семье
     */
    public function families(): iterable
    {
        $refundsBySource = [];
        Payment::query()->whereIn('status', Payment::PAID_STATUSES)
            ->whereNotNull('refund_of_payment_id')
            ->orderBy('id')
            ->get(['id', 'amount', 'refund_of_payment_id', 'created_at'])
            ->each(function (Payment $r) use (&$refundsBySource): void {
                $refundsBySource[(int) $r->refund_of_payment_id][] = $r;
            });

        $this->evidenceSeen = [];

        foreach (Payment::query()->whereIn('status', Payment::PAID_STATUSES)->whereNull('refund_of_payment_id')->orderBy('id')->lazyById(500) as $p) {
            $refunds = $refundsBySource[$p->id] ?? [];
            unset($refundsBySource[$p->id]);
            yield $this->plan($p, $refunds);
        }

        // Связанный возврат, чей источник не оплаченный исходный платёж
        // (не оплачен, сам возврат, удалён): ядро такой возврат не примет.
        foreach ($refundsBySource as $sourceId => $refunds) {
            foreach ($refunds as $r) {
                yield [
                    'payment_id' => $r->id,
                    'user_id' => null,
                    'course_id' => null,
                    'occurred_at' => Carbon::parse($r->getRawOriginal('created_at') ?? 'now'),
                    'legacy_kopecks' => Kopecks::fromDecimal((string) $r->getRawOriginal('amount')),
                    'category' => self::CAT_ORPHAN_REFUND,
                    'receipt' => null,
                    'obligations' => [],
                    'allocations' => [],
                    'refunds' => [],
                    'anomalies' => ['linked_refund_source_not_a_paid_receipt'],
                    'source_payment_id' => $sourceId,
                ];
            }
        }
    }

    /**
     * @param  list<Payment>  $refunds
     * @return array<string, mixed>
     */
    public function plan(Payment $p, array $refunds = []): array
    {
        $kopecks = Kopecks::fromDecimal((string) $p->getRawOriginal('amount'));
        $plan = [
            'payment_id' => $p->id,
            'user_id' => $p->user_id,
            'course_id' => $p->course_id,
            'occurred_at' => Carbon::parse($p->getRawOriginal('created_at') ?? 'now'),
            'legacy_kopecks' => $kopecks,
            'category' => null,
            'receipt' => null,
            'obligations' => [],
            'allocations' => [],
            'refunds' => [],
            'anomalies' => [],
        ];

        if ($p->tariff === 'Расход') {
            $plan['category'] = self::CAT_UNLINKED_OUTFLOW;
            $plan['anomalies'][] = 'outflow_without_source_link';

            return $plan;
        }
        if ($p->tariff === 'salary_payout') {
            $plan['category'] = self::CAT_PAYOUT_P2;

            return $plan;
        }
        if ($p->user_id === null) {
            $plan['category'] = self::CAT_NO_STUDENT;
            $plan['anomalies'][] = 'receipt_without_student';

            return $plan;
        }
        if ($kopecks <= 0) {
            $plan['category'] = self::CAT_NONPOSITIVE;
            $plan['anomalies'][] = 'nonpositive_receipt';

            return $plan;
        }

        $evidence = $this->evidenceKey($p, $plan['anomalies']);
        $account = (string) ($p->getRawOriginal('received_account') ?? Payment::RECEIVED_SCHOOL);
        $direct = $account !== Payment::RECEIVED_SCHOOL;

        $plan['receipt'] = [
            'type' => $direct ? MoneyMovement::DIRECT_TEACHER_RECEIPT : MoneyMovement::RECEIPT,
            'kopecks' => $kopecks,
            'evidence_key' => $evidence,
            'teacher_id' => $p->received_by_teacher_id,
            'source_currency' => 'RUB',
        ];

        if ($direct) {
            $plan['category'] = self::CAT_DIRECT;
            if ($account !== Payment::RECEIVED_TEACHER) {
                $plan['anomalies'][] = 'received_account_not_teacher_personal';
            }
            if ($evidence === null) {
                $plan['anomalies'][] = 'direct_receipt_without_evidence';
            }
        }

        $discount = $p->getRawOriginal('discount_amount') !== null ? Kopecks::fromDecimal((string) $p->getRawOriginal('discount_amount')) : 0;
        if ($discount === 0 && (float) $p->getRawOriginal('discount_percent') > 0) {
            $plan['anomalies'][] = 'discount_percent_without_amount';
        }

        if ($p->tariff === 'deposit') {
            $plan['category'] ??= self::CAT_DEPOSIT;
            $plan['obligations'][] = ['kind' => 'deposit', 'key' => "legacy:{$p->id}:deposit", 'list' => $kopecks, 'discount' => 0, 'block' => null];
            $plan['allocations'][] = [0, $kopecks];
            if ((float) $p->getRawOriginal('consumed_amount') > 0) {
                $plan['anomalies'][] = 'deposit_application_target_unknown';
            }
        } elseif ($p->tariff === 'trial') {
            $plan['category'] ??= self::CAT_TRIAL;
            $plan['obligations'][] = ['kind' => 'trial', 'key' => "legacy:{$p->id}:trial", 'list' => $kopecks + $discount, 'discount' => $discount, 'block' => null];
            $plan['allocations'][] = [0, $kopecks];
        } elseif ($p->start_block !== null && $p->course_id !== null) {
            $plan['category'] ??= self::CAT_BLOCK;
            $from = (int) $p->start_block;
            $to = max($from, (int) ($p->end_block ?? $from));
            if ($p->end_block !== null && (int) $p->end_block < $from) {
                $plan['anomalies'][] = 'end_block_before_start_block';
            }
            $n = $to - $from + 1;
            $paid = Kopecks::split($kopecks, $n);
            $disc = Kopecks::split($discount, $n);
            foreach (range(0, $n - 1) as $i) {
                $plan['obligations'][] = ['kind' => 'block', 'key' => "legacy:{$p->id}:block:".($from + $i), 'list' => $paid[$i] + $disc[$i], 'discount' => $disc[$i], 'block' => $from + $i];
                $plan['allocations'][] = [$i, $paid[$i]];
            }
        } else {
            // «full» и прочее: назначение по блокам неизвестно — явный остаток (D2).
            $plan['category'] ??= self::CAT_UNALLOCATED;
            $plan['anomalies'][] = 'purpose_unknown_left_unallocated';
        }

        foreach ($refunds as $r) {
            $plan['refunds'][] = ['payment_id' => $r->id, 'kopecks' => abs(Kopecks::fromDecimal((string) $r->getRawOriginal('amount'))), 'occurred_at' => Carbon::parse($r->getRawOriginal('created_at') ?? 'now')];
        }
        $refunded = array_sum(array_column($plan['refunds'], 'kopecks'));
        if ($refunded > $kopecks) {
            $plan['anomalies'][] = 'legacy_refunds_exceed_source';
        }

        return $plan;
    }

    /** Сумма распределений плана (для контроля «до копейки»). */
    public static function allocatedKopecks(array $plan): int
    {
        return array_sum(array_column($plan['allocations'], 1));
    }

    /**
     * @param  list<string>  $anomalies
     */
    private function evidenceKey(Payment $p, array &$anomalies): ?string
    {
        $txn = trim((string) $p->getRawOriginal('transaction_id'));
        if ($txn === '') {
            return null;
        }
        $key = 'legacy-txn:'.$txn;
        if (isset($this->evidenceSeen[$key])) {
            $anomalies[] = 'evidence_reused';

            return null;
        }
        $this->evidenceSeen[$key] = $p->id;

        return $key;
    }
}
