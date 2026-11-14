<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\PaymentReconcile;

/**
 * H6188: чистая логика read-only сверки оплат — находки «платежи vs записи
 * Systema vs ожидания по курсу/балансу». Никаких запросов и записей: одинаковые
 * факты сценария всегда дают одинаковые находки (на этом стоит контроль
 * дрейфа, паритет с App\Services\Reconciliation\EvidenceClassifier).
 *
 * Правило пары: событие ↔ запись сверяются по (kind, amount, currency);
 * kind события — payment/refund, kind записи — из status (paid→payment,
 * refunded→refund, иначе пара не образуется). Статусы находок:
 *  - amount_mismatch / unmatched_payment / missing_payment / refund_mismatch /
 *    expectation_mismatch — «жёсткие» (стоп по runbook ДЕН) → эскалация;
 *  - duplicate_suspected — подозрение на дубль (ambiguity) → человек.
 */
final class W1PaymentReconcileAnalyzer
{
    /**
     * @return list<W1PaymentReconcileFinding>
     */
    public function analyze(W1PaymentReconcileScenario $scenario): array
    {
        $open = $scenario->records;
        $matchedRecords = [];
        $leftoverEvents = [];

        foreach ($scenario->events as $event) {
            $hit = null;
            foreach ($open as $i => $record) {
                if ($this->pairs($event, $record)) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit === null) {
                $leftoverEvents[] = $event;
            } else {
                $matchedRecords[] = $open[$hit];
                unset($open[$hit]);
            }
        }
        $leftoverRecords = array_values($open);

        $findings = [];

        if ($leftoverEvents !== [] && $leftoverRecords !== []) {
            $hasRefund = false;
            foreach (array_merge($leftoverEvents, $leftoverRecords) as $item) {
                if ($item['kind'] === W1PaymentReconcileScenario::KIND_REFUND) {
                    $hasRefund = true;
                    break;
                }
            }
            $findings[] = $hasRefund
                ? new W1PaymentReconcileFinding(
                    W1PaymentReconcileFinding::REFUND_MISMATCH,
                    'возврат(ы) не сверяются с записями Systema: событие '.$this->describe($leftoverEvents[0])
                        .' vs запись '.$this->describe($leftoverRecords[0]),
                    array_merge($leftoverEvents, $leftoverRecords),
                )
                : new W1PaymentReconcileFinding(
                    W1PaymentReconcileFinding::AMOUNT_MISMATCH,
                    'суммы источников расходятся: событие '.$this->describe($leftoverEvents[0])
                        .' vs запись '.$this->describe($leftoverRecords[0]),
                    array_merge($leftoverEvents, $leftoverRecords),
                );
        } elseif ($leftoverEvents !== []) {
            foreach ($leftoverEvents as $event) {
                $duplicate = false;
                foreach ($matchedRecords as $record) {
                    if ($this->pairs($event, $record)) {
                        $duplicate = true;
                        break;
                    }
                }
                if ($duplicate) {
                    $findings[] = new W1PaymentReconcileFinding(
                        W1PaymentReconcileFinding::DUPLICATE_SUSPECTED,
                        'повторное событие '.$this->describe($event).' при одной сверенной записи — подозрение на дубль',
                        [$event],
                    );
                } elseif ($event['kind'] === W1PaymentReconcileScenario::KIND_REFUND) {
                    $findings[] = new W1PaymentReconcileFinding(
                        W1PaymentReconcileFinding::REFUND_MISMATCH,
                        'возврат '.$this->describe($event).' не сверяется с записью Systema',
                        [$event],
                    );
                } else {
                    $findings[] = new W1PaymentReconcileFinding(
                        W1PaymentReconcileFinding::UNMATCHED_PAYMENT,
                        'платёж '.$this->describe($event).' без записи Systema',
                        [$event],
                    );
                }
            }
        } elseif ($leftoverRecords !== []) {
            foreach ($leftoverRecords as $record) {
                $type = $record['kind'] === W1PaymentReconcileScenario::KIND_REFUND
                    ? W1PaymentReconcileFinding::REFUND_MISMATCH
                    : W1PaymentReconcileFinding::MISSING_PAYMENT;
                $findings[] = new W1PaymentReconcileFinding(
                    $type,
                    'запись Systema '.$this->describe($record).' («'.$record['status'].'») без сверенного события',
                    [$record],
                );
            }
        }

        array_push($findings, ...$this->expectationFindings($scenario));

        return $findings;
    }

    /** @param list<W1PaymentReconcileFinding> $findings */
    public function hasHard(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->isHard()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Платежи vs ожидания по курсу/тарифу (rate) и по балансу (balance).
     *
     * @return list<W1PaymentReconcileFinding>
     */
    private function expectationFindings(W1PaymentReconcileScenario $scenario): array
    {
        $expectations = $scenario->expectations;
        if ($expectations['amount'] === null && $expectations['balance_delta'] === null) {
            return [];
        }

        $currency = $expectations['currency'] ?? $this->singleCurrency($scenario->events);
        if ($currency === null) {
            return [new W1PaymentReconcileFinding(
                W1PaymentReconcileFinding::EXPECTATION_MISMATCH,
                'ожидание не сверимо: в событиях несколько валют, currency ожидания не задан',
                [],
                'rate',
            )];
        }

        $paid = $this->sumKind($scenario->events, W1PaymentReconcileScenario::KIND_PAYMENT, $currency);
        $refunded = $this->sumKind($scenario->events, W1PaymentReconcileScenario::KIND_REFUND, $currency);

        $findings = [];
        if ($expectations['amount'] !== null && $paid !== $expectations['amount']) {
            $findings[] = new W1PaymentReconcileFinding(
                W1PaymentReconcileFinding::EXPECTATION_MISMATCH,
                'платежи '.$paid.' '.$currency.' ≠ ожидание по курсу '.$expectations['amount'].' '.$currency,
                [],
                'rate',
            );
        }
        if ($expectations['balance_delta'] !== null && ($paid - $refunded) !== $expectations['balance_delta']) {
            $findings[] = new W1PaymentReconcileFinding(
                W1PaymentReconcileFinding::EXPECTATION_MISMATCH,
                'баланс событий '.($paid - $refunded).' '.$currency.' ≠ ожидание по балансу '.$expectations['balance_delta'].' '.$currency,
                [],
                'balance',
            );
        }

        return $findings;
    }

    /**
     * Событие ↔ запись: kind и amount строго, валюта — совместима (запись без
     * валюты сверяется с любой валютой события).
     *
     * @param  array{kind: string, amount: int, currency: string}  $event
     * @param  array{kind: string, amount: int, currency: ?string}  $record
     */
    private function pairs(array $event, array $record): bool
    {
        return $event['kind'] === $record['kind']
            && $event['amount'] === $record['amount']
            && ($record['currency'] === null || $record['currency'] === $event['currency']);
    }

    /**
     * @param  array{kind: string, amount: int, currency: ?string, external_id?: ?string, status?: string}  $item
     */
    private function describe(array $item): string
    {
        $ref = isset($item['external_id']) && $item['external_id'] !== null ? ' ('.$item['external_id'].')' : '';
        $currency = $item['currency'] !== null ? ' '.$item['currency'] : '';

        return $item['amount'].$currency.$ref;
    }

    /**
     * @param  list<array{kind: string, amount: int, currency: string}>  $events
     */
    private function sumKind(array $events, string $kind, string $currency): int
    {
        $sum = 0;
        foreach ($events as $event) {
            if ($event['kind'] === $kind && $event['currency'] === $currency) {
                $sum += $event['amount'];
            }
        }

        return $sum;
    }

    /**
     * @param  list<array{kind: string, amount: int, currency: string}>  $events
     */
    private function singleCurrency(array $events): ?string
    {
        $currencies = [];
        foreach ($events as $event) {
            $currencies[$event['currency']] = true;
        }

        return count($currencies) === 1 ? (string) array_key_first($currencies) : null;
    }
}
