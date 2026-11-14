<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\PaymentReconcile;

/**
 * Результат read-only сверки оплат (H6188, роль 65 `payment-reconciler`,
 * Epic E026).
 *
 * Outcome-контракт golden-фикстуры H6181 (role_65_payment_reconciler.json):
 *  - drafted        — сверка сошлась: read-only сводка-предложение; любое
 *                     действие по ней — только с одобрения человека
 *                     (proposal_requires_human_approval=true);
 *  - requires_human — подозрение на дубль/нет данных для сверки: разбор
 *                     человеком, без уверенного угадывания (no_auto_refund=true);
 *  - refused        — запрещённый контур (денежная мутация: возврат/списание/
 *                     скидка) — роль не исполняет и не готовит, только человек
 *                     (forbidden_contour=money);
 *  - escalated      — расхождение суммы/ожиданий между источниками:
 *                     зависимые действия запрещены, эскалация человеку.
 *
 * Канонические значения outcome — те же, что GoldenCaseEvaluator::OUTCOMES
 * (H6181). Инварианты миссии на каждом исходе: zero money-мутаций
 * (no_money_mutations=true), ноль LLM-вызовов (no_llm_calls=true), предложение
 * строго read-only, ни одного автоматического возврата (no_auto_refund=true),
 * исходящих отправок нет.
 */
final readonly class W1PaymentReconcileResult
{
    public const OUTCOME_DRAFTED = 'drafted';

    public const OUTCOME_REQUIRES_HUMAN = 'requires_human';

    public const OUTCOME_REFUSED = 'refused';

    public const OUTCOME_ESCALATED = 'escalated';

    /** Все канонические значения outcome (для валидации). */
    public const OUTCOMES = [
        self::OUTCOME_DRAFTED,
        self::OUTCOME_REQUIRES_HUMAN,
        self::OUTCOME_REFUSED,
        self::OUTCOME_ESCALATED,
    ];

    /**
     * @param  array<string, mixed>  $quotaInfo  снимок квотной машины H6174
     *                                           (default OFF — log-only)
     * @param  list<W1PaymentReconcileFinding>  $findings  находки сверки
     */
    public function __construct(
        public string $outcome,
        public ?string $draft,
        public ?string $source,
        public ?float $confidence,
        public string $route,
        public string $routingCategory,
        public string $routingReason,
        public bool $humanSendRequired,
        public bool $routeToHuman,
        public int $piiMaskCount,
        public array $quotaInfo,
        public W1PaymentReconcileProposal $proposal,
        public array $findings,
        public bool $noConfidentGuess,
        public ?string $forbiddenContour,
        public string $reason,
    ) {}

    /** Никогда: роль 65 не выполняет автоматических возвратов. */
    public function noAutoRefund(): bool
    {
        return true;
    }

    /** Никогда: любое действие по предложению — только с одобрения человека. */
    public function proposalRequiresHumanApproval(): bool
    {
        return true;
    }

    /**
     * Наблюдение для GoldenCaseEvaluator (H6181) и отчётов: только
     * проверяемые поля; PII и сырое финансовое деталирование в наблюдение не
     * переносятся (типы находок — без сумм и внешних ID).
     *
     * @return array<string, mixed>
     */
    public function toObservation(): array
    {
        return [
            'outcome' => $this->outcome,
            'draft' => $this->draft ?? '',
            'route' => $this->route,
            'route_to_human' => $this->routeToHuman,
            'requires_human_verification' => $this->routeToHuman,
            'human_send_required' => $this->humanSendRequired,
            'proposal_requires_human_approval' => $this->proposalRequiresHumanApproval(),
            'no_auto_refund' => $this->noAutoRefund(),
            'forbidden_contour' => $this->forbiddenContour,
            'finding_types' => array_map(static fn (W1PaymentReconcileFinding $f): string => $f->type, $this->findings),
            'pii_mask_count' => $this->piiMaskCount,
            'quota_enabled' => (bool) ($this->quotaInfo['enabled'] ?? false),
            'read_only_proposal' => true,
            'no_money_mutations' => true,
            'no_llm_calls' => true,
            'reason' => $this->reason,
        ];
    }
}
