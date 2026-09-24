<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\MoneyReconException as X;

/**
 * H5445 (P3): чистая классификация строки доказательства по её фактам
 * (EvidenceCollector). Никаких запросов — одинаковые факты всегда дают
 * одинаковый результат, на этом стоит контроль дрейфа.
 *
 * Итог строки — ровно один класс: `matched`, `unallocated` (деньги честно
 * получены, но не разложены по блокам — явный остаток D2) или
 * `exception:<тип>` (первый по приоритету X::TYPES). Все найденные
 * нарушения при этом открываются в очереди, не только первое.
 */
final class EvidenceClassifier
{
    public const MATCHED = 'matched';

    public const UNALLOCATED = 'unallocated';

    private const NO_ACCESS_TARIFFS = ['deposit', 'trial', 'Расход', 'salary_payout'];

    /**
     * @param  array<string, mixed>  $row  факты строки
     * @param  array<string, mixed>  $ledger  срез ядра (status, legacy_links)
     * @return array{class: string, findings: list<array<string, mixed>>}
     */
    public function classify(array $row, array $ledger): array
    {
        $found = match ($row['source']) {
            EvidenceCollector::CH_WEBHOOK => $this->webhook($row),
            EvidenceCollector::CH_REFUND => $this->refund($row),
            default => $this->inflow($row, $ledger),
        };

        $findings = [];
        foreach ($found as [$type, $detail]) {
            $findings[] = $this->finding($row, $type, $detail);
        }

        usort($findings, fn ($a, $b) => [array_search($a['type'], X::TYPES, true), $a['evidence']['detail']]
            <=> [array_search($b['type'], X::TYPES, true), $b['evidence']['detail']]);

        if ($findings !== []) {
            return ['class' => 'exception:'.$findings[0]['type'], 'findings' => $findings];
        }

        return ['class' => $this->isUnallocated($row) ? self::UNALLOCATED : self::MATCHED, 'findings' => []];
    }

    /**
     * Нарушения, которые видны только на стороне ядра P1 (не привязаны к строке окна).
     *
     * @param  array<string, mixed>  $ledger
     * @return list<array<string, mixed>>
     */
    public function ledgerFindings(array $ledger): array
    {
        if (($ledger['status'] ?? null) !== 'present') {
            return [];
        }
        $out = [];
        foreach ($ledger['breaches'] ?? [] as $name => $ids) {
            $out[] = [
                'type' => X::LEDGER_MISMATCH,
                'source' => 'ledger',
                'source_ref' => 'ledger-breach:'.$name,
                'evidence' => ['detail' => 'integrity_breach:'.$name, 'ids' => array_values($ids)],
                'amount_kopecks' => null,
                'currency' => null,
                'user_id' => null,
            ];
        }
        foreach ($ledger['residues'] ?? [] as $anchorId => $residue) {
            $out[] = [
                'type' => X::UNALLOCATED_RESIDUE,
                'source' => 'ledger',
                'source_ref' => 'movement:'.$anchorId,
                'evidence' => ['detail' => 'family_unallocated_residue', 'anchor_movement_id' => (int) $anchorId, 'residue_kopecks' => (int) $residue],
                'amount_kopecks' => (int) $residue,
                'currency' => 'RUB',
                'user_id' => null,
            ];
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string}> */
    private function inflow(array $r, array $ledger): array
    {
        $f = [];

        if (($r['evidence_reuse'] ?? 1) > 1) {
            $f[] = [X::REUSED_EVIDENCE, 'evidence_key_used_'.$r['evidence_reuse'].'_times'];
        }

        $paid = in_array($r['status'], ['paid', 'success'], true);
        match ($r['claim_exception']) {
            null => null,
            'no_access_groups' => $f[] = [X::IMPOSSIBLE_ACCESS, 'claim:no_access_groups'],
            'supplement_amount_beyond_tolerance' => $f[] = [X::CURRENCY_AMOUNT_MISMATCH, 'claim:supplement_amount_beyond_tolerance'],
            default => $f[] = [X::UNKNOWN_PURPOSE, 'claim:'.$r['claim_exception']],
        };
        if ($paid && $r['amount_verdict'] === 'beyond_5') {
            $f[] = [X::CURRENCY_AMOUNT_MISMATCH, 'paid_beyond_5_percent'];
        }
        if ($paid && $r['amount_verdict'] === 'no_expected_price') {
            $f[] = [X::CURRENCY_AMOUNT_MISMATCH, 'paid_without_expected_price'];
        }
        if ($r['foreign_minor'] !== null && $r['foreign_minor'] > 0 && empty($r['foreign_currency'])) {
            $f[] = [X::CURRENCY_AMOUNT_MISMATCH, 'foreign_amount_without_currency'];
        }

        if ($r['amount_kopecks'] < 0) {
            $f[] = [X::UNKNOWN_PURPOSE, 'negative_inflow'];
        }
        if ($r['user_id'] === null) {
            $f[] = [X::UNKNOWN_PURPOSE, 'no_student'];
        }
        if ($r['course_id'] === null && ! in_array($r['tariff'], self::NO_ACCESS_TARIFFS, true)) {
            $f[] = [X::UNKNOWN_PURPOSE, 'no_course'];
        }

        if ($paid && $r['course_has_groups'] === false && ! in_array($r['tariff'], self::NO_ACCESS_TARIFFS, true)) {
            $f[] = [X::IMPOSSIBLE_ACCESS, 'course_without_access_groups'];
        }

        $paidAt = $r['paid_at'];
        if ($paidAt !== null && $r['link_expires_at'] !== null && $paidAt > $r['link_expires_at']) {
            $f[] = [X::EXPIRED_TERMS, 'paid_after_link_expiry'];
        }
        if ($paidAt !== null && $r['promo_expires_at'] !== null && $paidAt > $r['promo_expires_at']) {
            $f[] = [X::EXPIRED_TERMS, 'promo_expired_at_payment'];
        }
        if ($r['promo_usage_limit'] !== null && $r['promo_rank'] !== null && $r['promo_rank'] > $r['promo_usage_limit']) {
            $f[] = [X::EXPIRED_TERMS, 'promo_limit_exceeded'];
        }

        if (($ledger['status'] ?? null) === 'present' && $paid) {
            $links = $ledger['legacy_links'] ?? [];
            if (! array_key_exists($r['payment_id'], $links)) {
                $f[] = [X::LEDGER_MISMATCH, 'not_in_ledger'];
            } elseif ((int) $links[$r['payment_id']] !== $r['amount_kopecks']) {
                $f[] = [X::LEDGER_MISMATCH, 'ledger_amount_differs'];
            }
        }

        return $f;
    }

    /** @return list<array{0: string, 1: string}> */
    private function refund(array $r): array
    {
        if ($r['original_missing'] ?? false) {
            return [[X::UNKNOWN_PURPOSE, 'refund_of_missing_payment']];
        }
        $f = [];
        if (! $r['refund_is_full']) {
            if ($r['start_block'] === null || $r['end_block'] === null) {
                $f[] = [X::REFUND_WITHOUT_BLOCKS, 'partial_refund_without_blocks'];
            } elseif ($r['original_block_from'] !== null
                && ($r['start_block'] < $r['original_block_from'] || $r['end_block'] > $r['original_block_to'])) {
                $f[] = [X::REFUND_WITHOUT_BLOCKS, 'refund_blocks_outside_paid_range'];
            }
        }
        if ($r['access_retained_after_full_refund']) {
            $f[] = [X::IMPOSSIBLE_ACCESS, 'access_retained_after_full_refund'];
        }

        return $f;
    }

    /** @return list<array{0: string, 1: string}> */
    private function webhook(array $r): array
    {
        return match ($r['decision']) {
            'unmatched' => [[X::UNKNOWN_PURPOSE, 'webhook_unmatched_order']],
            'rejected_amount_mismatch' => [[X::CURRENCY_AMOUNT_MISMATCH, 'webhook_amount_mismatch']],
            'rejected_resurrection' => [[X::UNKNOWN_PURPOSE, 'webhook_paid_for_cancelled_order']],
            'rejected_charge' => [[X::UNKNOWN_PURPOSE, 'webhook_rejected_charge']],
            // Банк подтвердил деньги, но предохранитель H4930 не выдал доступ — ждёт человека.
            'breaker_refused' => [[X::IMPOSSIBLE_ACCESS, 'webhook_breaker_refused']],
            // applied / duplicate / hold_not_captured (денег ещё нет) — сходится.
            default => [],
        };
    }

    private function isUnallocated(array $r): bool
    {
        if (in_array($r['source'], [EvidenceCollector::CH_WEBHOOK, EvidenceCollector::CH_REFUND], true)) {
            return false;
        }
        if (in_array($r['tariff'], self::NO_ACCESS_TARIFFS, true)) {
            return false;
        }

        return ! ($r['start_block'] !== null || (is_string($r['tariff']) && preg_match('/^block_\d+$/', $r['tariff']) === 1));
    }

    /** @return array<string, mixed> */
    private function finding(array $row, string $type, string $detail): array
    {
        $evidence = ['detail' => $detail];
        foreach (['payment_id', 'webhook_id', 'provider', 'status', 'tariff', 'course_id', 'amount_kopecks', 'foreign_currency', 'foreign_minor',
            'start_block', 'end_block', 'paid_at', 'evidence_keys', 'claim_exception', 'amount_verdict', 'link_expires_at', 'promo_code_id',
            'promo_expires_at', 'promo_usage_limit', 'promo_rank', 'refund_of', 'original_kopecks', 'family_refunded_kopecks', 'decision', 'bank_status', 'event_hash'] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $evidence[$k] = $row[$k];
            }
        }

        return [
            'type' => $type,
            'source' => $row['source'],
            'source_ref' => $row['ref'],
            'evidence' => $evidence,
            'amount_kopecks' => $row['amount_kopecks'] ?? null,
            'currency' => $row['currency'] ?? null,
            'user_id' => $row['user_id'] ?? null,
        ];
    }
}
