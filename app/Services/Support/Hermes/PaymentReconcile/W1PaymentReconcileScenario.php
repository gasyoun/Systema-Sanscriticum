<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\PaymentReconcile;

use InvalidArgumentException;

/**
 * H6188 (роль 65 `payment-reconciler`, Epic E026): машиночитаемый сценарий
 * сверки оплат. Вход — СИНТЕТИЧЕСКИЕ платёжные события (Tochka-сторона),
 * записи Systema и ожидания (по курсу/тарифу и по балансу); provenance
 * фикстур — H6181 (synthetic, без ученических переписок).
 *
 * Формат (супerset контракта role_65_payment_reconciler.json):
 *  - `paid_event` (один) либо `paid_events` (список) — {amount, currency, external_id?};
 *  - `refund_event` / `refund_events` — та же форма, kind=refund;
 *  - `systema_record` (один) либо `systema_records` — {amount, status}; status
 *    `paid` → kind=payment, `refunded` → kind=refund, иначе пара не образуется;
 *  - `expectations` (опционально) — {amount?} «платежи vs ожидание по курсу»,
 *    {balance_delta?} «платежи vs ожидание по балансу», {currency?};
 *  - `note` — служебная метка фикстуры; в предложение и отчёт НЕ переносится.
 *
 * Деньги — целые единицы без float (правило H5445: «деньги — копейки»),
 * сравнение строго по (kind, amount, currency). Никаких записей в БД и
 * внешних вызовов — чистый разбор входа.
 */
final readonly class W1PaymentReconcileScenario
{
    public const KIND_PAYMENT = 'payment';

    public const KIND_REFUND = 'refund';

    /**
     * @param  list<array{kind: string, amount: int, currency: string, external_id: ?string}>  $events
     * @param  list<array{kind: string, amount: int, currency: ?string, status: string}>  $records
     * @param  array{amount: ?int, balance_delta: ?int, currency: ?string}  $expectations
     */
    public function __construct(
        public array $events,
        public array $records,
        public array $expectations,
        public ?string $note,
    ) {}

    /**
     * @param  array<string, mixed>  $scenario
     *
     * @throws InvalidArgumentException при нарушении контракта входа
     */
    public static function fromArray(array $scenario): self
    {
        $events = [];
        foreach (self::listOf($scenario, 'paid_event', 'paid_events') as $raw) {
            $events[] = self::event(self::KIND_PAYMENT, $raw, 'paid_event');
        }
        foreach (self::listOf($scenario, 'refund_event', 'refund_events') as $raw) {
            $events[] = self::event(self::KIND_REFUND, $raw, 'refund_event');
        }
        if ($events === []) {
            throw new InvalidArgumentException('h6188 scenario: paid_event(s) or refund_event(s) required');
        }

        $records = [];
        foreach (self::listOf($scenario, 'systema_record', 'systema_records') as $raw) {
            $records[] = self::record($raw);
        }

        $expectations = self::expectations($scenario['expectations'] ?? null);

        $note = null;
        if (isset($scenario['note']) && is_string($scenario['note'])) {
            $note = $scenario['note'];
        }

        return new self($events, $records, $expectations, $note);
    }

    /** Ключ пары «событие ↔ запись»: kind|amount|currency. */
    public static function pairKey(string $kind, int $amount, string $currency): string
    {
        return $kind.'|'.$amount.'|'.$currency;
    }

    /** @return array{kind: string, amount: int, currency: string, external_id: ?string} */
    private static function event(string $kind, mixed $raw, string $label): array
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException("h6188 scenario: {$label} must be an object");
        }

        return [
            'kind' => $kind,
            'amount' => self::amount($raw, $label),
            'currency' => self::currency($raw, $label),
            'external_id' => isset($raw['external_id']) && is_string($raw['external_id']) ? $raw['external_id'] : null,
        ];
    }

    /**
     * Запись Systema: currency опциональна (фикстуры H6181 несут только
     * amount+status) — отсутствующая валюта сверяется с любой валютой события.
     *
     * @return array{kind: string, amount: int, currency: ?string, status: string}
     */
    private static function record(mixed $raw): array
    {
        $label = 'systema_record';
        if (! is_array($raw)) {
            throw new InvalidArgumentException("h6188 scenario: {$label} must be an object");
        }
        $status = $raw['status'] ?? null;
        if (! is_string($status) || $status === '') {
            throw new InvalidArgumentException("h6188 scenario: {$label}.status required");
        }

        return [
            'kind' => match ($status) {
                'paid' => self::KIND_PAYMENT,
                'refunded' => self::KIND_REFUND,
                default => 'other',
            },
            'amount' => self::amount($raw, $label),
            'currency' => isset($raw['currency']) ? self::currency($raw, $label) : null,
            'status' => $status,
        ];
    }

    /** @return array{amount: ?int, balance_delta: ?int, currency: ?string} */
    private static function expectations(mixed $raw): array
    {
        $out = ['amount' => null, 'balance_delta' => null, 'currency' => null];
        if ($raw === null) {
            return $out;
        }
        if (! is_array($raw)) {
            throw new InvalidArgumentException('h6188 scenario: expectations must be an object');
        }
        foreach (['amount', 'balance_delta'] as $key) {
            if (array_key_exists($key, $raw) && $raw[$key] !== null) {
                if (! is_int($raw[$key]) || $raw[$key] < 0) {
                    throw new InvalidArgumentException("h6188 scenario: expectations.{$key} must be int >= 0");
                }
                $out[$key] = $raw[$key];
            }
        }
        if (isset($raw['currency'])) {
            $out['currency'] = self::currency($raw, 'expectations');
        }

        return $out;
    }

    /** @param array<string, mixed> $raw */
    private static function amount(array $raw, string $label): int
    {
        $amount = $raw['amount'] ?? null;
        if (! is_int($amount) || $amount < 0) {
            throw new InvalidArgumentException("h6188 scenario: {$label}.amount must be int >= 0 (no floats — money)");
        }

        return $amount;
    }

    /** @param array<string, mixed> $raw */
    private static function currency(array $raw, string $label): string
    {
        $currency = $raw['currency'] ?? null;
        if (! is_string($currency) || trim($currency) === '') {
            throw new InvalidArgumentException("h6188 scenario: {$label}.currency required");
        }

        return $currency;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return list<mixed>
     */
    private static function listOf(array $scenario, string $single, string $plural): array
    {
        if (isset($scenario[$single])) {
            return [$scenario[$single]];
        }
        if (isset($scenario[$plural])) {
            if (! is_array($scenario[$plural])) {
                throw new InvalidArgumentException("h6188 scenario: {$plural} must be a list");
            }

            return array_values($scenario[$plural]);
        }

        return [];
    }
}
