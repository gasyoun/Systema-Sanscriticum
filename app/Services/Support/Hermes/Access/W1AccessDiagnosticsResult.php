<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Access;

/**
 * H6189 (Epic E026, Hermes W1): результат диагностики доступа — роль 85
 * `access-provisioner` (диагностика и предложение исправления).
 *
 * Outcome-контракт golden-фикстуры H6181 (role_85_access_provisioner.json):
 *  - drafted — диагноз + проект операции собраны как ДАННЫЕ для человека
 *              (happy: «диагноз + проект операции выдачи; исполнение —
 *              только подтверждение человеком»);
 *  - requires_human — неоднозначность/спор (ambiguity) либо квота: без
 *              уверенного угадывания, решение за человеком;
 *  - refused — роль не предлагает операцию без основания (forbidden:
 *              «неподтверждённая оплата — не основание для выдачи доступа»);
 *  - escalated — прямая эскалация (escalation: массовый инцидент, runbook
 *              H6133).
 *
 * Состояния права (миссия H6189: «действующее/истёкшее/чужое/отсутствующее
 * право») — семантика соответствует каноническим предикатам доступа
 * (CourseAccessWindow H4456: активное/истёкшее окно; отсутствие строки;
 * чужой владелец — PII не раскрывается).
 *
 * Никаких исходящих отправок в результате нет (human-send per H6183);
 * проект операции — только данные ({@see W1AccessFixProposal}).
 */
final readonly class W1AccessDiagnosticsResult
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

    /** Состояния права (миссия H6189). */
    public const STATE_ACTIVE = 'active';

    public const STATE_EXPIRED = 'expired';

    public const STATE_FOREIGN = 'foreign';

    public const STATE_MISSING = 'missing';

    public const STATE_UNKNOWN = 'unknown';

    /** Классы основания (оплата). */
    public const BASIS_CONFIRMED = 'confirmed';

    public const BASIS_DISPUTED = 'disputed';

    public const BASIS_UNCONFIRMED = 'unconfirmed';

    public const BASIS_UNKNOWN = 'unknown';

    /**
     * @param  string  $outcome  одно из OUTCOMES
     * @param  string|null  $accessState  одно из STATE_* (null — диагностика
     *                                    не выполнялась: роль OFF / отказ)
     * @param  string|null  $basis  одно из BASIS_* (null — не определялось)
     * @param  string  $basisExplanation  объяснение основания (данные для
     *                                    человека, без персональных данных)
     * @param  array<string, mixed>  $quotaInfo  снимок квотной машины H6174
     */
    public function __construct(
        public string $outcome,
        public ?string $accessState,
        public ?string $basis,
        public string $basisExplanation,
        public ?W1AccessFixProposal $proposal,
        public ?string $draft,
        public ?string $source,
        public ?float $confidence,
        public string $route,
        public ?string $routingCategory,
        public string $routingReason,
        public bool $routeToHuman,
        public bool $mustNotAutoRevoke,
        public ?string $forbiddenContour,
        public bool $massIncident,
        public ?string $maskedRequest,
        public int $piiMaskCount,
        public array $quotaInfo,
    ) {}

    /** Наблюдение для GoldenCaseEvaluator (H6181): только проверяемые поля. */
    public function toObservation(): array
    {
        return [
            'outcome' => $this->outcome,
            'draft' => $this->draft ?? '',
            'requires_human_verification' => $this->routeToHuman,
            'no_side_effects' => true,
        ];
    }

    /**
     * Закрытый исход (роль OFF / PII-граница / нет данных состояния).
     *
     * @param  array<string, mixed>  $quotaInfo
     */
    public static function closed(
        string $outcome,
        string $reason,
        array $quotaInfo,
        ?string $state = null,
        ?string $basis = null,
        string $basisExplanation = '',
        ?string $maskedRequest = null,
        int $piiMaskCount = 0,
    ): self {
        return new self(
            outcome: $outcome,
            accessState: $state,
            basis: $basis,
            basisExplanation: $basisExplanation !== '' ? $basisExplanation : 'закрытый исход: '.$reason,
            proposal: null,
            draft: null,
            source: null,
            confidence: null,
            route: 'human',
            routingCategory: null,
            routingReason: $reason,
            routeToHuman: true,
            mustNotAutoRevoke: false,
            forbiddenContour: null,
            massIncident: false,
            maskedRequest: $maskedRequest,
            piiMaskCount: $piiMaskCount,
            quotaInfo: $quotaInfo,
        );
    }

    /**
     * Отказ (forbidden): без проекта операции — роль не предлагает операцию
     * без основания (ap-003).
     *
     * @param  array<string, mixed>  $quotaInfo
     */
    public static function forbiddenRefusal(
        string $reason,
        ?string $category,
        string $contour,
        ?string $maskedRequest,
        int $piiMaskCount,
        array $quotaInfo,
    ): self {
        return new self(
            outcome: self::OUTCOME_REFUSED,
            accessState: null,
            basis: null,
            basisExplanation: 'основание отсутствует: запрещённый контур ('.$reason.')',
            proposal: null,
            draft: null,
            source: null,
            confidence: null,
            route: 'human',
            routingCategory: $category,
            routingReason: $reason,
            routeToHuman: true,
            mustNotAutoRevoke: false,
            forbiddenContour: $contour,
            massIncident: false,
            maskedRequest: $maskedRequest,
            piiMaskCount: $piiMaskCount,
            quotaInfo: $quotaInfo,
        );
    }

    /**
     * Прямая эскалация человеку (массовый инцидент / триггеры runbook H6133).
     *
     * @param  array<string, mixed>  $quotaInfo
     */
    public static function escalation(
        bool $massIncident,
        ?string $category,
        string $reason,
        string $basisExplanation,
        ?string $maskedRequest,
        int $piiMaskCount,
        array $quotaInfo,
    ): self {
        return new self(
            outcome: self::OUTCOME_ESCALATED,
            accessState: null,
            basis: null,
            basisExplanation: $basisExplanation,
            proposal: null,
            draft: null,
            source: null,
            confidence: null,
            route: 'human',
            routingCategory: $category,
            routingReason: $reason,
            routeToHuman: true,
            mustNotAutoRevoke: false,
            forbiddenContour: null,
            massIncident: $massIncident,
            maskedRequest: $maskedRequest,
            piiMaskCount: $piiMaskCount,
            quotaInfo: $quotaInfo,
        );
    }
}
