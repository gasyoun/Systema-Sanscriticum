<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\FaqRag;

/**
 * H6185 (Epic E026, Hermes W1): результат роли 20 `faq-rag` — RAG-ответ с
 * ПРОВЕРЯЕМЫМИ цитатами.
 *
 * Outcome-контракт golden-фикстуры H6181 (role_20_faq_rag.json):
 *  - drafted — ответ только с проверенной цитатой утверждённого FAQ-источника
 *    (must_cite; исходящие отправки НЕ входят в результат — human-send per
 *    H6183: черновик уходит в очередь человеку, роль ничего не отправляет);
 *  - refused — per-student факт (delegate_hint роли 23), запрещённый контур
 *    (money) или непроверяемая цитата (missing/stale/contradictory);
 *  - escalated — прямая эскалация человеку (runbook H6133);
 *  - requires_human — роль OFF (W1 deploy gate) или PII-граница закрыта.
 *
 * citations — только ПРОВЕРЕННЫЕ записи (ключ разрешим в корпусе И сниппет
 * подтверждён); failed_citations — причины отказа для аудита.
 */
final readonly class W1FaqRagResult
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
     * @param  list<array{chunk_id: string, snippet: string}>  $citations  проверенные записи
     * @param  list<array{chunk_id: string, problem: string}>  $failedCitations
     */
    public function __construct(
        public string $outcome,
        public ?string $draft,
        public ?string $source,
        public ?float $confidence,
        public string $route,
        public bool $humanSendRequired,
        public bool $routeToHuman,
        public bool $noConfidentGuess,
        public ?string $delegateHint,
        public ?string $forbiddenContour,
        public string $refusalReason,
        public int $piiMaskCount,
        public array $citations,
        public array $failedCitations,
        public bool $llmCalled,
        public float $scoreFloor,
        public ?float $bestScore,
    ) {}

    /** Ответ прошёл верификацию цитат: есть подтверждённые и нет отказов. */
    public function citationsVerified(): bool
    {
        return $this->citations !== [] && $this->failedCitations === [];
    }

    /**
     * Наблюдение для GoldenCaseEvaluator (H6181) + версионированные ключи
     * expected role_20_faq_rag.json (route_to_human, human_send_required,
     * must_cite, no_confident_guess, delegate_hint, forbidden_contour).
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
            'route_to_human' => $this->routeToHuman,
            'human_send_required' => $this->humanSendRequired,
            'citations_verified' => $this->citationsVerified(),
            'citations' => array_values(array_map(
                static fn (array $c): string => (string) $c['chunk_id'],
                $this->citations,
            )),
            'failed_citations' => $this->failedCitations,
            'no_confident_guess' => $this->noConfidentGuess,
            'delegate_hint' => $this->delegateHint,
            'forbidden_contour' => $this->forbiddenContour,
            'refusal_reason' => $this->refusalReason,
            'llm_called' => $this->llmCalled,
            'pii_mask_count' => $this->piiMaskCount,
        ];
    }
}
