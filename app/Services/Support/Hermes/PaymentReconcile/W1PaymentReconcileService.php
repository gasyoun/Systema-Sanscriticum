<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\PaymentReconcile;

use App\Services\Support\Hermes\Quota\QuotaLane;
use App\Services\Support\Hermes\Quota\SchoolRoleQuotaService;
use App\Services\Support\Hermes\Routing\W1FirstContactRoutingLayer;
use App\Services\Support\Hermes\Routing\W1RoutingDecision;
use App\Services\Support\SupportStudentPiiMasker;

/**
 * H6188 (Epic E026, Hermes W1): роль 65 `payment-reconciler` — READ-ONLY
 * предложение сверки оплат. Сверяет синтетические payment/duplicate/refund/
 * mismatch данные (платежи vs записи Systema vs ожидания по курсу/балансу)
 * и формирует адресное предложение человеку через разрешённый контур
 * (human-send per H6183). Свёртка фактов — {@see W1PaymentReconcileAnalyzer},
 * текст предложения — {@see W1PaymentReconcileComposer}.
 *
 * Границы (exclusions миссии, класс money):
 *  - ZERO money-мутаций: ни одного списания, возврата, скидки и ни одной
 *    записи в денежные таблицы — только данные сценария в памяти процесса;
 *  - ZERO реальных платёжных API и ZERO LLM-вызовов: предложение
 *    композится детерминированно из структурных фактов, никакой сети;
 *  - финансовое сырьё не передаётся LLM (вызовов нет вообще), PII не
 *    достигает LLM: входной текст маскируется H6182 fail-closed;
 *  - роль OFF (features.hermes_payment_reconciler, default OFF) — никакого
 *    production-включения; включение — deploy-шаг человека;
 *  - квота H6174: reserve→композиция→commit, default OFF (log-only); при
 *    отказе квоты — очередь человеку, платного fallback нет;
 *  - маршрутизация — общий слой H6190 (W1FirstContactRoutingLayer,
 *    in-memory, идемпотентно по ключу события) — не параллельный механизм;
 *  - исходящих отправок нет ни на одном пути.
 *
 * Приоритет исходов: refused → escalated → requires_human → drafted.
 * Вход: либо машиночитаемый сценарий ({@see self::reconcile}), либо текст
 * обращения ({@see self::handle}) — текст БЕЗ сценарных данных никогда не
 * даёт drafted (никакого уверенного угадывания).
 */
final class W1PaymentReconcileService
{
    /** Канонический slug роли 65 ростера V2 (payment-reconciler, H6181). */
    public const ROLE_SLUG = 'payment-reconciler';

    /** ID роли 65 ростера V2 (для навигации; каном — GoldenCaseEvaluator). */
    public const ROLE_ID = 65;

    /** Оценка токенов композиции предложения (mock path, паритет с ролью 19). */
    private const ESTIMATED_TOKENS = 100;

    /** Денежные мутации в требовании — запрещённый контур (manifest H6181). */
    private const MONEY_MUTATION_PATTERN = '/возврат|refund|списа|спиш|скидк|промокод|перерасч|долг|задолжен|деньг|оплат/iu';

    public function __construct(
        private readonly W1FirstContactRoutingLayer $routingLayer = new W1FirstContactRoutingLayer,
        private readonly SupportStudentPiiMasker $piiMasker = new SupportStudentPiiMasker,
        private readonly SchoolRoleQuotaService $quotaService = new SchoolRoleQuotaService,
        private readonly W1PaymentReconcileAnalyzer $analyzer = new W1PaymentReconcileAnalyzer,
        private readonly W1PaymentReconcileComposer $composer = new W1PaymentReconcileComposer,
    ) {}

    /** Роль 65 OFF по умолчанию (W1 deploy gate): включение — решение человека. */
    public function isEnabled(): bool
    {
        return (bool) config('features.hermes_payment_reconciler', false);
    }

    /**
     * Сценарный путь: read-only сверка машиночитаемых данных оплат.
     *
     * @param  array<string, mixed>  $scenario  {@see W1PaymentReconcileScenario}
     */
    public function reconcile(string $eventKey, array $scenario): W1PaymentReconcileResult
    {
        if (! $this->isEnabled()) {
            return $this->closed('role_disabled', W1RoutingDecision::ambiguity('role_disabled'));
        }

        // 1. Находки сверки (чистая логика над данными сценария).
        $parsed = W1PaymentReconcileScenario::fromArray($scenario);
        $findings = $this->analyzer->analyze($parsed);

        // 2. Решение из находок → общий слой H6190 (идемпотентная запись).
        $decision = match (true) {
            $this->analyzer->hasHard($findings) => W1RoutingDecision::escalation($findings[0]->type),
            $findings !== [] => W1RoutingDecision::ambiguity($findings[0]->type),
            default => W1RoutingDecision::toRole(self::ROLE_ID, 'payment_reconcile_matched'),
        };
        $assigned = $this->routingLayer->assignDecision($eventKey, $decision);
        $decision = $assigned->assignment->decision;

        $quotaInfo = $this->quotaStatusOnly();

        // 3. Находки есть → эскалация/человек без композиции черновика.
        $preliminary = match (true) {
            $this->analyzer->hasHard($findings) => W1PaymentReconcileResult::OUTCOME_ESCALATED,
            $findings !== [] => W1PaymentReconcileResult::OUTCOME_REQUIRES_HUMAN,
            default => W1PaymentReconcileResult::OUTCOME_DRAFTED,
        };

        if ($preliminary !== W1PaymentReconcileResult::OUTCOME_DRAFTED) {
            return $this->result(
                outcome: $preliminary,
                draft: null,
                source: 'payment-reconcile:'.$findings[0]->type,
                confidence: null,
                decision: $decision,
                humanSendRequired: false,
                routeToHuman: true,
                piiMaskCount: 0,
                quotaInfo: $quotaInfo,
                proposal: $this->composer->fromScenario($parsed, $findings),
                findings: $findings,
                noConfidentGuess: $preliminary === W1PaymentReconcileResult::OUTCOME_REQUIRES_HUMAN,
                forbiddenContour: null,
                reason: $findings[0]->type,
            );
        }

        // 4. Happy-путь: композиция read-only предложения через квоту H6174.
        $composition = $this->composeProposalWithQuota($eventKey, $parsed, $findings);
        $quotaInfo['draft_verdict'] = $composition['quota_verdict'];

        if ($composition['proposal'] === null) {
            // Квота отказала (при включённой машине): fallback=none → человек.
            return $this->result(
                outcome: W1PaymentReconcileResult::OUTCOME_REQUIRES_HUMAN,
                draft: null,
                source: null,
                confidence: null,
                decision: $decision,
                humanSendRequired: false,
                routeToHuman: true,
                piiMaskCount: 0,
                quotaInfo: $quotaInfo,
                proposal: new W1PaymentReconcileProposal(
                    'Сверка оплат: квотная машина отказала (fallback=none) — очередь человеку. '
                        .W1PaymentReconcileComposer::APPROVAL_LINE,
                    $findings,
                ),
                findings: $findings,
                noConfidentGuess: false,
                forbiddenContour: null,
                reason: 'quota_denied',
            );
        }

        return $this->result(
            outcome: W1PaymentReconcileResult::OUTCOME_DRAFTED,
            draft: $composition['proposal']->summary,
            source: 'payment-reconcile:'.$decision->reason,
            confidence: 1.0,
            decision: $decision,
            humanSendRequired: true,
            routeToHuman: false,
            piiMaskCount: 0,
            quotaInfo: $quotaInfo,
            proposal: $composition['proposal'],
            findings: $findings,
            noConfidentGuess: false,
            forbiddenContour: null,
            reason: 'matched',
        );
    }

    /**
     * Текстовый путь: обращение про оплату. Маршрутизация через общий слой
     * H6190 (routeAndAssign), PII-маскирование H6182 fail-closed. Текст без
     * сценарных данных никогда не даёт drafted: без данных сверки роль не
     * угадывает — человеку.
     *
     * @param  array<string, mixed>  $context  контракт слоёв H6190/H6182
     *                                         (channel, author, author_name,
     *                                         author_handle, sensitive,
     *                                         prompt_injection_suspected)
     */
    public function handle(string $eventKey, string $message, array $context = []): W1PaymentReconcileResult
    {
        if (! $this->isEnabled()) {
            return $this->closed('role_disabled', W1RoutingDecision::ambiguity('role_disabled'));
        }

        // 1. Маршрутизация через общий слой H6190 (in-memory, без циклов и дублей).
        $routingResult = $this->routingLayer->routeAndAssign($eventKey, $message, $context);
        $decision = $routingResult->assignment->decision;

        // 2. PII-маскирование входа (H6182 fail-closed: чистоту нельзя доказать
        //    → текст исключён; текст и так не переносится в предложение).
        [, $piiMaskCount] = $this->maskInput($message, $context);

        // 3. Исход по категории решения H6190 (refused → escalated → requires_human).
        if ($decision->category === W1RoutingDecision::CATEGORY_FORBIDDEN) {
            return $this->result(
                outcome: W1PaymentReconcileResult::OUTCOME_REFUSED,
                draft: null,
                source: null,
                confidence: null,
                decision: $decision,
                humanSendRequired: false,
                routeToHuman: true,
                piiMaskCount: $piiMaskCount,
                quotaInfo: $this->quotaStatusOnly(),
                proposal: $this->composer->fromMessage(
                    'Требование денежной мутации отклонено: роль 65 не исполняет и не готовит возвраты/списания/скидки — только человек.',
                    [],
                ),
                findings: [],
                noConfidentGuess: false,
                forbiddenContour: $this->isMoneyMutationDemand($message) ? 'money' : 'access',
                reason: 'money_mutation_demand',
            );
        }

        if ($decision->category === W1RoutingDecision::CATEGORY_ESCALATION) {
            return $this->result(
                outcome: W1PaymentReconcileResult::OUTCOME_ESCALATED,
                draft: null,
                source: null,
                confidence: null,
                decision: $decision,
                humanSendRequired: false,
                routeToHuman: true,
                piiMaskCount: $piiMaskCount,
                quotaInfo: $this->quotaStatusOnly(),
                proposal: $this->composer->fromMessage(
                    'Обращение эскалировано человеку (runbook H6133: '.$decision->reason.').',
                    [],
                ),
                findings: [],
                noConfidentGuess: false,
                forbiddenContour: null,
                reason: $decision->reason,
            );
        }

        // ambiguity либо happy (обращение без машиночитаемых данных сверки):
        // без уверенного угадывания — человеку.
        return $this->result(
            outcome: W1PaymentReconcileResult::OUTCOME_REQUIRES_HUMAN,
            draft: null,
            source: null,
            confidence: null,
            decision: $decision,
            humanSendRequired: false,
            routeToHuman: true,
            piiMaskCount: $piiMaskCount,
            quotaInfo: $this->quotaStatusOnly(),
            proposal: $this->composer->fromMessage(
                'Обращение передано человеку: данных для сверки нет (машиночитаемый сценарий не передан).',
                [],
            ),
            findings: [],
            noConfidentGuess: true,
            forbiddenContour: null,
            reason: $decision->category === W1RoutingDecision::CATEGORY_HAPPY
                ? 'no_scenario_data_for_reconcile'
                : $decision->reason,
        );
    }

    /**
     * Композиция предложения через квотную машину H6174 (default OFF —
     * log-only). При включённой машине полный цикл reserve → композиция →
     * commit; реальных LLM-вызовов и платных API нет (mock path).
     *
     * @param  list<W1PaymentReconcileFinding>  $findings
     * @return array{proposal: ?W1PaymentReconcileProposal, quota_verdict: array<string, mixed>}
     */
    private function composeProposalWithQuota(string $eventKey, W1PaymentReconcileScenario $scenario, array $findings): array
    {
        $verdict = $this->quotaService->reserve(
            self::ROLE_SLUG,
            QuotaLane::ROUTINE,
            self::ESTIMATED_TOKENS,
            'h6188:reconcile:'.$eventKey,
            false, // не эксперимент — штатная работа
        );

        if (! $verdict->allowed) {
            // Квота отказала: ноль LLM-вызовов, платного fallback нет (H6174).
            return ['proposal' => null, 'quota_verdict' => $verdict->toArray()];
        }

        $proposal = $this->composer->fromScenario($scenario, $findings);

        if ($verdict->reservationId !== null) {
            $this->quotaService->commit($verdict->reservationId, self::ESTIMATED_TOKENS);
        }

        return ['proposal' => $proposal, 'quota_verdict' => $verdict->toArray()];
    }

    /**
     * PII-маскирование входа по контракту H6182 (fail-closed).
     *
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: int} замаскированный текст и число подстановок
     */
    private function maskInput(string $message, array $context): array
    {
        try {
            $this->piiMasker->newConversation();

            $names = [];
            $nicks = [];
            if (! empty($context['author_name'])) {
                $names[] = (string) $context['author_name'];
            }
            if (! empty($context['author_handle'])) {
                $nicks[] = (string) $context['author_handle'];
            }
            if ($names !== [] || $nicks !== []) {
                $this->piiMasker->seedConversation($names, $nicks);
            }

            return $this->piiMasker->maskOrFail($message);
        } catch (\Throwable) {
            // Fail-closed (H6182): чистоту нельзя доказать → текст исключён.
            return ['', 0];
        }
    }

    /** Денежная мутация в требовании (возврат/списание/скидка/долг). */
    private function isMoneyMutationDemand(string $message): bool
    {
        return preg_match(self::MONEY_MUTATION_PATTERN, $message) === 1;
    }

    /**
     * Снимок квоты роли (read-only) — log-only при default OFF.
     *
     * @return array<string, mixed>
     */
    private function quotaStatusOnly(): array
    {
        try {
            $status = $this->quotaService->status(self::ROLE_SLUG);

            return array_merge(
                $status->toArray(),
                ['enabled' => $this->quotaService->enabled()],
            );
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'enabled' => false];
        }
    }

    /** Роль OFF / закрытый контур: без черновика, очередь человеку. */
    private function closed(string $reason, W1RoutingDecision $decision): W1PaymentReconcileResult
    {
        return $this->result(
            outcome: W1PaymentReconcileResult::OUTCOME_REQUIRES_HUMAN,
            draft: null,
            source: null,
            confidence: null,
            decision: $decision,
            humanSendRequired: false,
            routeToHuman: true,
            piiMaskCount: 0,
            quotaInfo: $this->quotaStatusOnly(),
            proposal: new W1PaymentReconcileProposal(
                'Сверка оплат: роль 65 OFF (W1 deploy gate) — сверка не выполняется, очередь человеку. '
                    .W1PaymentReconcileComposer::APPROVAL_LINE,
                [],
            ),
            findings: [],
            noConfidentGuess: false,
            forbiddenContour: null,
            reason: $reason,
        );
    }

    /**
     * @param  array<string, mixed>  $quotaInfo
     * @param  list<W1PaymentReconcileFinding>  $findings
     */
    private function result(
        string $outcome,
        ?string $draft,
        ?string $source,
        ?float $confidence,
        W1RoutingDecision $decision,
        bool $humanSendRequired,
        bool $routeToHuman,
        int $piiMaskCount,
        array $quotaInfo,
        W1PaymentReconcileProposal $proposal,
        array $findings,
        bool $noConfidentGuess,
        ?string $forbiddenContour,
        string $reason,
    ): W1PaymentReconcileResult {
        return new W1PaymentReconcileResult(
            outcome: $outcome,
            draft: $draft,
            source: $source,
            confidence: $confidence,
            route: $decision->route,
            routingCategory: $decision->category,
            routingReason: $decision->reason,
            humanSendRequired: $humanSendRequired,
            routeToHuman: $routeToHuman,
            piiMaskCount: $piiMaskCount,
            quotaInfo: $quotaInfo,
            proposal: $proposal,
            findings: $findings,
            noConfidentGuess: $noConfidentGuess,
            forbiddenContour: $forbiddenContour,
            reason: $reason,
        );
    }
}
