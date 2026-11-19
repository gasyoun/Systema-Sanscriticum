<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Onboarding;

use App\Models\SupportAnswerSuggestion;
use App\Services\Support\Hermes\Quota\QuotaLane;
use App\Services\Support\Hermes\Quota\SchoolRoleQuotaService;
use App\Services\Support\Hermes\Routing\W1FirstContactRoutingLayer;
use App\Services\Support\Hermes\Routing\W1RoutingDecision;
use App\Services\Support\SupportQuestionCategorizer;
use App\Services\Support\SupportStudentPiiMasker;
use App\Support\OnboardingChecklist;

/**
 * H6186 (Epic E026, Hermes W1): безопасная подготовка привязки к боту —
 * роль 19 `onboarding-greeter` (ID 19 ростера V2).
 *
 * Сервис собирает поток подготовки привязки: проверяет принадлежность
 * аккаунта, чужой account, истёкший token и replay ({@see W1BotBindingChecks});
 * подготавливает действие человеку ({@see W1BotBindingPrepAction}), НЕ выполняя
 * привязку автоматически. Deep-link/токен — только чистые данные
 * ({@see W1BotBindingTokenIssuer}); любой reusable token не попадает в черновик
 * (LLM) и не попадает в отчёт (toObservation / evidence). Роль OFF:
 * прод-включения нет, исходящих отправок нет (первые 30 дней human-send —
 * H6183).
 *
 * Потребитель общих слоёв (EXTEND, не дублировать):
 *  - маршрутизация — общий слой H6190 (W1FirstContactRoutingLayer, in-memory,
 *    идемпотентно; инъекция/запреты/ранбук-триггеры решаются там);
 *  - PII — SupportStudentPiiMasker (H6182, fail-closed: чистоту нельзя
 *    доказать → текст исключён из промпта);
 *  - квота — SchoolRoleQuotaService (H6174, default OFF — log-only; role slug
 *    `onboarding-greeter` — канонический ID ростера, новых ID не создаётся);
 *  - golden-контракт исходов — GoldenCaseEvaluator H6181
 *    (role_19_onboarding.json);
 *  - шаги черновика — OnboardingChecklist::bindingSteps() (утверждённый
 *    чек-лист привязки; текст-основа docs/onboarding-student.md +
 *    messenger-connect.blade.php).
 *
 * Канонический механизм привязки остаётся единным и не трогается здесь:
 * выдача токена — TelegramController::start(), применение —
 * TelegramWebhookController (`/start {token}`). Этот сервис только
 * ПОДГОТАВЛИВАЕТ данные для человека («binding tokens are data, never
 * applied»).
 *
 * Приоритет исходов (безопасность прежде всего): refused → escalated →
 * requires_human → drafted (детали — в composeOutcome). Escalation — по
 * триггерам runbook docs/RUNBOOK_SUPPORT_ESCALATION.md (решение слоя H6190)
 * либо binding_state=failing_repeatedly; requires_human — без уверенного
 * угадывания; drafted — черновик строго из утверждённого чек-листа +
 * действие-данные (при истёкшем токене — свежий, tokenRenewed).
 *
 * Границы (exclusions миссии): никакого production-включения (роль 19 OFF),
 * реальных отправок, финансовых мутаций, автоматического изменения доступов,
 * новых расписаний и платных API; mock/synthetic; стенограммы, session stores
 * и сырые ученические переписки не читаются; PII не достигает LLM; новых
 * канонических role ID нет.
 */
final class W1BotBindingPrepService
{
    /** Канонический slug роли 19 ростера V2 (onboarding-greeter). */
    public const ROLE_SLUG = 'onboarding-greeter';

    /** ID роли 19 ростера V2 (для навигации; каном — GoldenCaseEvaluator). */
    public const ROLE_ID = 19;

    /** Оценка токенов композиции черновика (mock path, паритет с ролью 13). */
    private const ESTIMATED_TOKENS = 100;

    /**
     * Уровень уверенности черновика: композиция строго из утверждённого
     * чек-листа детерминирована (выше, чем у шаблонного mock-LLM H6184),
     * но итоговая отправка и проверка принадлежности — за человеком (H6183).
     */
    private const CHECKLIST_CONFIDENCE = 0.9;

    /** Утверждённое приветствие первой линии (домашний регистр, H1876/ранбук). */
    private const GREETING = 'Здравствуйте!';

    /** Утверждённая цель привязки (docs/onboarding-student.md, verbatim). */
    private const PURPOSE = 'Привяжите Telegram, чтобы получать уведомления и писать в поддержку.';

    public function __construct(
        private readonly W1FirstContactRoutingLayer $routingLayer = new W1FirstContactRoutingLayer,
        private readonly SupportStudentPiiMasker $piiMasker = new SupportStudentPiiMasker,
        private readonly SchoolRoleQuotaService $quotaService = new SchoolRoleQuotaService,
        private readonly W1BotBindingTokenIssuer $tokenIssuer = new W1BotBindingTokenIssuer,
    ) {}

    /**
     * Подготовить привязку: маршрутизация (H6190) → PII-маскирование (H6182,
     * fail-closed) → привязочные проверки (принадлежность/чужой account/
     * истёкший token/replay) → исход + действие-данные + квота (H6174).
     *
     * Контекст события — см. {@see W1BotBindingChecks} (машиночитаемый
     * контракт: binding_state, third_party_account, author_telegram_id,
     * account_owner_telegram_id, binding_token_state, claimed_link_token,
     * claimed_user_id) плюс общий контракт слоёв H6190/H6182 (channel, author,
     * author_name, author_handle, sensitive, prompt_injection_suspected).
     */
    public function handle(string $eventKey, string $message, array $context = []): W1BotBindingPrepResult
    {
        // 1. Маршрутизация через общий слой H6190 (in-memory, без циклов и дублей).
        $routingResult = $this->routingLayer->routeAndAssign($eventKey, $message, $context);
        $decision = $routingResult->assignment->decision;

        // 2. PII-маскирование входа (H6182 fail-closed: чистоту нельзя доказать
        //    → текст исключён из промпта — вызова нет).
        [$maskedText, $piiMaskCount] = $this->maskInput($message, $context);

        // 3. Привязочные проверки (чистая логика + read-only сверка).
        $checks = W1BotBindingChecks::fromContext($context);

        // 4. Исход + действие-данные + квотная машина H6174 (default OFF — log-only).
        return $this->composeOutcome($eventKey, $message, $decision, $checks, $piiMaskCount, $maskedText);
    }

    /**
     * Приоритет исходов: refused → escalated → requires_human → drafted.
     */
    private function composeOutcome(
        string $eventKey,
        string $message,
        W1RoutingDecision $decision,
        W1BotBindingChecks $checks,
        int $piiMaskCount,
        string $maskedText,
    ): W1BotBindingPrepResult {
        $tokenState = $checks->tokenState;
        $quotaInfo = $this->quotaStatusOnly();

        // 1. Refused: запрещённый контур (решение слоя H6190), чужой account
        //    или replay токена.
        if ($decision->category === W1RoutingDecision::CATEGORY_FORBIDDEN
            || $checks->isForeignAccount()
            || $checks->isReplay()
        ) {
            return $this->result(
                outcome: W1BotBindingPrepResult::OUTCOME_REFUSED,
                draft: null,
                source: null,
                confidence: null,
                decision: $decision,
                humanSendRequired: false,
                routeToHuman: true,
                piiMaskCount: $piiMaskCount,
                quotaInfo: $quotaInfo,
                action: null,
                checks: $checks->toArray(),
                tokenState: $tokenState,
                stepsFromApprovedChecklistOnly: false,
                noConfidentGuess: false,
                forbiddenContour: $this->forbiddenContour($message, $checks),
            );
        }

        // 2. Escalated: триггеры runbook H6133 (решение слоя) либо
        //    многократный сбой привязки — чек-лист не помогает, человеку.
        if ($decision->category === W1RoutingDecision::CATEGORY_ESCALATION
            || $checks->bindingState === 'failing_repeatedly'
        ) {
            return $this->result(
                outcome: W1BotBindingPrepResult::OUTCOME_ESCALATED,
                draft: null,
                source: null,
                confidence: null,
                decision: $decision,
                humanSendRequired: false,
                routeToHuman: true,
                piiMaskCount: $piiMaskCount,
                quotaInfo: $quotaInfo,
                action: null,
                checks: $checks->toArray(),
                tokenState: $tokenState,
                stepsFromApprovedChecklistOnly: false,
                noConfidentGuess: false,
                forbiddenContour: null,
            );
        }

        // 3. Requires human: неоднозначность (решение слоя) либо состояние
        //    привязки/токена неизвестно — без уверенного угадывания.
        if ($decision->category === W1RoutingDecision::CATEGORY_AMBIGUITY
            || $checks->bindingState === 'unknown'
            || $checks->isUnverifiable()
        ) {
            return $this->result(
                outcome: W1BotBindingPrepResult::OUTCOME_REQUIRES_HUMAN,
                draft: null,
                source: null,
                confidence: null,
                decision: $decision,
                humanSendRequired: false,
                routeToHuman: true,
                piiMaskCount: $piiMaskCount,
                quotaInfo: $quotaInfo,
                action: null,
                checks: $checks->toArray(),
                tokenState: $tokenState,
                stepsFromApprovedChecklistOnly: false,
                noConfidentGuess: true,
                forbiddenContour: null,
            );
        }

        // 4. Drafted: happy-путь — черновик строго из утверждённого чек-листа
        //    привязки + действие-данные (токен/deep link как данные).
        $composition = $this->composeDraftWithQuota($eventKey, $maskedText, $decision);
        $quotaInfo['draft_verdict'] = $composition['quota_verdict'];

        if ($composition['draft'] === null) {
            // Квота отказала (при включённой машине) — очередь человеку,
            // платного fallback нет (H6174: fallback=none).
            return $this->result(
                outcome: W1BotBindingPrepResult::OUTCOME_REQUIRES_HUMAN,
                draft: null,
                source: null,
                confidence: null,
                decision: $decision,
                humanSendRequired: false,
                routeToHuman: true,
                piiMaskCount: $piiMaskCount,
                quotaInfo: $quotaInfo,
                action: null,
                checks: $checks->toArray(),
                tokenState: $tokenState,
                stepsFromApprovedChecklistOnly: false,
                noConfidentGuess: false,
                forbiddenContour: null,
            );
        }

        return $this->result(
            outcome: W1BotBindingPrepResult::OUTCOME_DRAFTED,
            draft: $composition['draft'],
            source: $composition['source'],
            confidence: $composition['confidence'],
            decision: $decision,
            humanSendRequired: true,
            routeToHuman: false,
            piiMaskCount: $piiMaskCount,
            quotaInfo: $quotaInfo,
            action: $this->tokenIssuer->issue($tokenState),
            checks: $checks->toArray(),
            tokenState: $tokenState,
            stepsFromApprovedChecklistOnly: true,
            noConfidentGuess: false,
            forbiddenContour: null,
        );
    }

    /**
     * Черновик строго из утверждённого чек-листа привязки через квотную
     * машину H6174 (default OFF — log-only). При выключенной машине reserve()
     * возвращает disabled-вердикт и черновик строится без enforcement; при
     * включённой — полный цикл reserve → композиция → commit. Реальных
     * LLM-вызовов и платных API нет (mock path).
     *
     * @return array{draft: ?string, source: ?string, confidence: ?float, quota_verdict: array<string, mixed>}
     */
    private function composeDraftWithQuota(string $eventKey, string $maskedText, W1RoutingDecision $decision): array
    {
        $verdict = $this->quotaService->reserve(
            self::ROLE_SLUG,
            QuotaLane::ROUTINE,
            self::ESTIMATED_TOKENS,
            'h6186:onboarding:'.$eventKey,
            false, // не эксперимент — штатная работа
        );

        if (! $verdict->allowed) {
            // Квота отказала: ноль LLM-вызовов, платного fallback нет (H6174).
            return [
                'draft' => null,
                'source' => null,
                'confidence' => null,
                'quota_verdict' => $verdict->toArray(),
            ];
        }

        $draft = self::composeChecklistDraft();

        if ($verdict->reservationId !== null) {
            $this->quotaService->commit($verdict->reservationId, self::ESTIMATED_TOKENS);
        }

        return [
            'draft' => $draft,
            'source' => 'onboarding:binding-checklist:'.$decision->reason,
            'confidence' => self::CHECKLIST_CONFIDENCE,
            'quota_verdict' => $verdict->toArray(),
        ];
    }

    /**
     * Черновик привязки строго из утверждённого чек-листа
     * ({@see OnboardingChecklist::bindingSteps()}) + утверждённые
     * приветствие/цель (docs/onboarding-student.md). Никакого свободного
     * копирайта, никакого текста пользователя (reusable token и PII не
     * достигают LLM).
     */
    public static function composeChecklistDraft(): string
    {
        $lines = [self::GREETING.' '.self::PURPOSE];

        $n = 1;
        foreach (OnboardingChecklist::bindingSteps() as $step) {
            $lines[] = $n.'. '.$step['label'].' — '.$step['hint'];
            $n++;
        }

        return implode("\n", $lines);
    }

    /**
     * Контур запрета: «access» — контур доступа/привязки этого сервиса
     * (чужой account, replay, мутационное требование привязки); «money» —
     * денежные требования (общий категоризатор, не параллельный механизм).
     */
    private function forbiddenContour(string $message, W1BotBindingChecks $checks): string
    {
        if ($checks->isForeignAccount() || $checks->isReplay()) {
            return 'access';
        }

        return SupportQuestionCategorizer::categorize($message) === SupportAnswerSuggestion::CATEGORY_PAYMENT
            ? 'money'
            : 'access';
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

    /**
     * @param  array<string, mixed>  $quotaInfo
     * @param  array<string, mixed>  $checks
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
        ?W1BotBindingPrepAction $action,
        array $checks,
        W1BindingTokenState $tokenState,
        bool $stepsFromApprovedChecklistOnly,
        bool $noConfidentGuess,
        ?string $forbiddenContour,
    ): W1BotBindingPrepResult {
        return new W1BotBindingPrepResult(
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
            action: $action,
            checks: $checks,
            tokenState: $tokenState->value,
            stepsFromApprovedChecklistOnly: $stepsFromApprovedChecklistOnly,
            noConfidentGuess: $noConfidentGuess,
            forbiddenContour: $forbiddenContour,
        );
    }
}
