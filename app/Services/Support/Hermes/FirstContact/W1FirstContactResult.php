<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\FirstContact;

/**
 * H6184 (Epic E026, Hermes W1): результат первого контакта — роль 13
 * (первый ответ и эскалация).
 *
 * Outcome-контракт golden-фикстуры H6181 (role_13_first_contact.json):
 *  - drafted — черновик первого ответа (happy, роль 13);
 *  - requires_human — без уверенного угадывания (ambiguity) или квота;
 *  - refused — запрещённый контур (forbidden);
 *  - escalated — прямая эскалация (escalation, runbook H6133).
 *
 * Черновик несёт источник (source) и уровень уверенности (confidence);
 * исходящие отправки НЕ входят в результат — human-send per H6183.
 */
final readonly class W1FirstContactResult
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
        public int $piiMaskCount,
        public array $quotaInfo,
    ) {}

    /**
     * Наблюдение для GoldenCaseEvaluator (H6181): только проверяемые поля.
     *
     * @return array<string, mixed>
     */
    public function toObservation(): array
    {
        return [
            'outcome' => $this->outcome,
            'draft' => $this->draft ?? '',
            'requires_human_verification' => $this->humanSendRequired,
            'no_side_effects' => true,
        ];
    }
}
