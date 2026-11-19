<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Onboarding;

/**
 * Результат безопасной подготовки привязки к боту (H6186, роль 19
 * `onboarding-greeter`, Epic E026, Hermes W1).
 *
 * Outcome-контракт golden-фикстуры H6181 (role_19_onboarding.json):
 *  - drafted       — черновик строго из утверждённого чек-листа привязки,
 *                    отправка человеком (human-send per H6183);
 *  - requires_human — состояние неизвестно/не доказано: человеком, без
 *                    уверенного угадывания (no_confident_guess);
 *  - refused       — запрещённый контур доступа (чужой account, replay,
 *                    мутационное требование привязки) — без черновика;
 *  - escalated     — многократный сбой/триггеры runbook H6133 — человеку.
 *
 * Канонические значения outcome те же, что у GoldenCaseEvaluator::OUTCOMES
 * (H6181) — никакой параллельной вселенной исходов.
 *
 * Инварианты: reusable token не попадает в toObservation() (отчёт golden-
 * прогона) и не входит в черновик (LLM); привязка не применяется никогда
 * (binding tokens are data, never applied); исходящих отправок нет.
 */
final readonly class W1BotBindingPrepResult
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
     * @param  array<string, mixed>  $quotaInfo  снимок квотной машины H6174 (default OFF — log-only)
     * @param  array<string, mixed>  $checks  вердикты привязочных проверок
     *                                        (ownership / foreign_account / token_state / replay_detected /
     *                                        expired_token / binding_state) — без токенов и PII
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
        public ?W1BotBindingPrepAction $action,
        public array $checks,
        public string $tokenState,
        public bool $stepsFromApprovedChecklistOnly,
        public bool $noConfidentGuess,
        public ?string $forbiddenContour,
    ) {}

    /**
     * Наблюдение для GoldenCaseEvaluator (H6181) и отчётов: только проверяемые
     * поля, БЕЗ reusable token и deep link (H6186: «не попадёт в отчёт или LLM»).
     *
     * @return array<string, mixed>
     */
    public function toObservation(): array
    {
        return [
            'outcome' => $this->outcome,
            'draft' => $this->draft ?? '',
            'route_to_human' => $this->routeToHuman,
            'requires_human_verification' => $this->routeToHuman,
            'human_send_required' => $this->humanSendRequired,
            'steps_from_approved_checklist_only' => $this->stepsFromApprovedChecklistOnly,
            'no_confident_guess' => $this->noConfidentGuess,
            'forbidden_contour' => $this->forbiddenContour,
            'token_state' => $this->tokenState,
            'binding_applied' => false,
            'reusable_token_in_report_or_llm' => false,
            'no_side_effects' => true,
        ];
    }
}
