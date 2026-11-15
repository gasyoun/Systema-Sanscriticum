<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\PaymentReconcile;

/**
 * H6188: одна находка read-only сверки оплат (платежи vs записи Systema vs
 * ожидания по курсу/балансу). Находка — ДАННЫЕ для человека: никаких
 * автоматических действий по ней не выполняется и не предлагается
 * (zero money-мутаций, ноль возвратов/списаний/скидок).
 */
final readonly class W1PaymentReconcileFinding
{
    /** Сумма события не совпадает с суммой записи Systema. */
    public const AMOUNT_MISMATCH = 'amount_mismatch';

    /** Подозрение на дубль: лишнее событие с уже «съеденной» суммой. */
    public const DUPLICATE_SUSPECTED = 'duplicate_suspected';

    /** Платёж без записи Systema (несверенная транзакция). */
    public const UNMATCHED_PAYMENT = 'unmatched_payment';

    /** Запись Systema без платёжного события. */
    public const MISSING_PAYMENT = 'missing_payment';

    /** Возврат не сверяется с записью (или запись «refunded» без события). */
    public const REFUND_MISMATCH = 'refund_mismatch';

    /** Платежи vs ожидания: по курсу/тарифу (rate) или по балансу (balance). */
    public const EXPECTATION_MISMATCH = 'expectation_mismatch';

    /** Все типы находок. */
    public const TYPES = [
        self::AMOUNT_MISMATCH,
        self::DUPLICATE_SUSPECTED,
        self::UNMATCHED_PAYMENT,
        self::MISSING_PAYMENT,
        self::REFUND_MISMATCH,
        self::EXPECTATION_MISMATCH,
    ];

    /**
     * «Жёсткие» расхождения — стоп по runbook ДЕН («любое расхождение
     * суммы/получателя/основания — стоп») → эскалация человеку.
     */
    public const HARD_TYPES = [
        self::AMOUNT_MISMATCH,
        self::UNMATCHED_PAYMENT,
        self::MISSING_PAYMENT,
        self::REFUND_MISMATCH,
        self::EXPECTATION_MISMATCH,
    ];

    /**
     * @param  string  $type  один из {@see TYPES}
     * @param  string  $detail  человекочитаемое описание (без PII)
     * @param  list<array<string, mixed>>  $subjects  события/записи-участники
     * @param  ?string  $expectationKind  для EXPECTATION_MISMATCH: rate|balance
     */
    public function __construct(
        public string $type,
        public string $detail,
        public array $subjects = [],
        public ?string $expectationKind = null,
    ) {}

    public function isHard(): bool
    {
        return in_array($this->type, self::HARD_TYPES, true);
    }
}
