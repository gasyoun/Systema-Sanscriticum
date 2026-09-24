<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Reconciliation\ReconInvariantViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5445 (P3, D2/D4/D8/D10/D16): типизированное исключение сверки. Доказательство
 * заморожено при открытии; состояние меняет только ExceptionQueue, и каждый
 * переход пишет событие в money_recon_exception_events. Закрытое не
 * переоткрывается — новое доказательство даёт новый ключ.
 *
 * @property int $id
 * @property string $exception_key
 * @property string $type
 * @property string $source
 * @property string $source_ref
 * @property array $evidence
 * @property ?int $amount_kopecks
 * @property ?string $currency
 * @property ?int $user_id
 * @property string $state
 * @property ?int $first_run_id
 * @property ?int $last_seen_run_id
 */
class MoneyReconException extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const DISMISSED = 'dismissed';

    /** Деньги пришли, а назначение (студент/курс/тариф/заказ) не определить. */
    public const UNKNOWN_PURPOSE = 'unknown_purpose';

    /** Валюта или сумма не совпала с ожидаемой (D4/D20; webhook amount mismatch). */
    public const CURRENCY_AMOUNT_MISMATCH = 'currency_amount_mismatch';

    /** Оплата после истечения ссылки, промокода или его лимита (D8). */
    public const EXPIRED_TERMS = 'expired_terms';

    /** Доступ нельзя выдать или он остался после полного возврата (D8/D10). */
    public const IMPOSSIBLE_ACCESS = 'impossible_access';

    /** Деньги получены, но не распределены по блокам/обязательствам (D2). */
    public const UNALLOCATED_RESIDUE = 'unallocated_residue';

    /** Одно доказательство подтверждает несколько оплат (D16). */
    public const REUSED_EVIDENCE = 'reused_evidence';

    /** Частичный возврат без распределения по блокам (D10). */
    public const REFUND_WITHOUT_BLOCKS = 'refund_without_blocks';

    /** Ядро P1 расходится с доказательством или нарушает свои инварианты. */
    public const LEDGER_MISMATCH = 'ledger_mismatch';

    /** Порядок = приоритет классификации строки (первое совпадение). */
    public const TYPES = [
        self::REUSED_EVIDENCE,
        self::CURRENCY_AMOUNT_MISMATCH,
        self::UNKNOWN_PURPOSE,
        self::REFUND_WITHOUT_BLOCKS,
        self::IMPOSSIBLE_ACCESS,
        self::EXPIRED_TERMS,
        self::LEDGER_MISMATCH,
        self::UNALLOCATED_RESIDUE,
    ];

    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'amount_kopecks' => 'integer',
        'resolved_at' => 'datetime',
    ];

    private const FROZEN = ['exception_key', 'type', 'source', 'source_ref', 'evidence', 'amount_kopecks', 'currency', 'user_id', 'first_run_id'];

    protected static function booted(): void
    {
        static::updating(function (MoneyReconException $e): void {
            if ($e->isDirty(self::FROZEN)) {
                throw new ReconInvariantViolation('recon: exception evidence is frozen');
            }
            if ($e->getOriginal('state') !== self::OPEN && $e->state === self::OPEN) {
                throw new ReconInvariantViolation('recon: a closed exception never reopens');
            }
        });
        static::deleting(fn () => throw new ReconInvariantViolation('recon: exceptions are never deleted'));
    }

    public function events(): HasMany
    {
        return $this->hasMany(MoneyReconExceptionEvent::class, 'exception_id')->orderBy('id');
    }
}
