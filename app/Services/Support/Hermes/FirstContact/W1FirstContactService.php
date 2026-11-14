<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\FirstContact;

use App\Services\Support\Hermes\Quota\QuotaLane;
use App\Services\Support\Hermes\Quota\SchoolRoleQuotaService;
use App\Services\Support\Hermes\Routing\W1FirstContactRoutingLayer;
use App\Services\Support\Hermes\Routing\W1RoutingDecision;
use App\Services\Support\SupportStudentPiiMasker;

/**
 * H6184 (Epic E026, Hermes W1): first-contact сервис — роль 13
 * (первый ответ и эскалация).
 *
 * Потребитель общего слоя маршрутизации H6190 (W1FirstContactRoutingLayer):
 * маршрутизует первое контакта через общий слой (без параллельного механизма),
 * маскирует PII ДО любого LLM-вызова (H6182 fail-closed), формирует черновик
 * первого ответа (mock LLM path — детерминированный шаблон без реальных
 * LLM-вызовов и платных API) и эскалирует по триггерам runbook H6133.
 *
 * Границы (exclusions миссии):
 *  - НЕТ production-включения и НЕТ исходящих отправок (human-send per H6183);
 *  - НЕТ платных API и реальных LLM-вызовов — mock/synthetic;
 *  - PII НЕ достигает LLM — маскирование ДО любого вызова (H6182);
 *  - квотная машина H6174 (default OFF) — log-only, без enforcement;
 *  - никаких новых канонических role ID — только ростер V2;
 *  - никаких записей в БД (routing layer — in-memory, quota — read-only при OFF).
 *
 * Связи: H6190 (routing), H6181 (golden fixture), H6182 (PII), H6174 (quota),
 * H6183 (human-send — за пределами этого сервиса).
 */
final class W1FirstContactService
{
    /** Канонический slug роли 13 ростера V2 (Uprava docs/FLEET_..._ROSTER_GRILL_04-10-2026.md). */
    public const ROLE_SLUG = 'first-contact-247';

    /** Оценка токенов для черновика первого ответа (mock path). */
    private const ESTIMATED_TOKENS = 100;

    /** Уровень уверенности mock-черновика (шаблонный ответ, детерминированный). */
    private const MOCK_CONFIDENCE = 0.75;

    public function __construct(
        private readonly W1FirstContactRoutingLayer $routingLayer = new W1FirstContactRoutingLayer,
        private readonly SupportStudentPiiMasker $piiMasker = new SupportStudentPiiMasker,
        private readonly SchoolRoleQuotaService $quotaService = new SchoolRoleQuotaService,
    ) {}

    /**
     * Обработать первое контакта: маршрутизация → PII-маскирование →
     * черновик/эскалация → квота.
     *
     * @param  array<string, mixed>  $context  машиночитаемый контекст события
     *                                         (channel, author, sensitive, prompt_injection_suspected, …)
     */
    public function handle(string $eventKey, string $message, array $context = []): W1FirstContactResult
    {
        // 1. Маршрутизация через общий слой H6190 (без циклов и дублей).
        $routingResult = $this->routingLayer->routeAndAssign($eventKey, $message, $context);
        $decision = $routingResult->assignment->decision;

        // 2. PII-маскирование входа (H6182 fail-closed: при недоступности
        //    identity-сета текст исключается из промпта — чистоту нельзя
        //    доказать → вызова нет).
        [$maskedText, $piiMaskCount] = $this->maskInput($message, $context);

        // 3. Формирование исхода + квотная машина H6174 (default OFF — log-only).
        return $this->composeOutcome($eventKey, $decision, $maskedText, $piiMaskCount);
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

    private function composeOutcome(
        string $eventKey,
        W1RoutingDecision $decision,
        string $maskedText,
        int $piiMaskCount,
    ): W1FirstContactResult {
        $outcome = match ($decision->category) {
            W1RoutingDecision::CATEGORY_HAPPY => W1FirstContactResult::OUTCOME_DRAFTED,
            W1RoutingDecision::CATEGORY_ESCALATION => W1FirstContactResult::OUTCOME_ESCALATED,
            W1RoutingDecision::CATEGORY_FORBIDDEN => W1FirstContactResult::OUTCOME_REFUSED,
            W1RoutingDecision::CATEGORY_AMBIGUITY => W1FirstContactResult::OUTCOME_REQUIRES_HUMAN,
        };

        // Черновик — только для happy + роль 13 (первый ответ).
        $draft = null;
        $source = null;
        $confidence = null;
        $humanSendRequired = false;
        $quotaInfo = $this->quotaStatusOnly();

        if ($outcome === W1FirstContactResult::OUTCOME_DRAFTED && $decision->roleId() === 13) {
            $composition = $this->composeDraftWithQuota($eventKey, $maskedText, $decision);
            $quotaInfo['draft_verdict'] = $composition['quota_verdict'];

            if ($composition['draft'] !== null) {
                $draft = $composition['draft'];
                $source = $composition['source'];
                $confidence = $composition['confidence'];
                $humanSendRequired = true;
            } else {
                // Квота отказала (при включённой машине) — в очередь человеку.
                $outcome = W1FirstContactResult::OUTCOME_REQUIRES_HUMAN;
            }
        }

        return new W1FirstContactResult(
            outcome: $outcome,
            draft: $draft,
            source: $source,
            confidence: $confidence,
            route: $decision->route,
            routingCategory: $decision->category,
            routingReason: $decision->reason,
            humanSendRequired: $humanSendRequired,
            piiMaskCount: $piiMaskCount,
            quotaInfo: $quotaInfo,
        );
    }

    /**
     * Черновик первого ответа через квотную машину H6174 (default OFF —
     * log-only). При выключенной машине reserve() возвращает disabled-вердикт
     * и черновик строится без enforcement. При включённой — полный цикл
     * reserve → mock LLM → commit.
     *
     * @return array{draft: ?string, source: ?string, confidence: ?float, quota_verdict: array<string, mixed>}
     */
    private function composeDraftWithQuota(string $eventKey, string $maskedText, W1RoutingDecision $decision): array
    {
        $verdict = $this->quotaService->reserve(
            self::ROLE_SLUG,
            QuotaLane::ROUTINE,
            self::ESTIMATED_TOKENS,
            'h6184:fc:'.$eventKey,
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

        // Mock LLM path: детерминированный шаблонный ответ без реальных вызовов.
        $draft = $this->mockLlmDraft($maskedText, $decision);

        // Фиксация расхода (reservationId === null при disabled — нет записи).
        if ($verdict->reservationId !== null) {
            $this->quotaService->commit($verdict->reservationId, self::ESTIMATED_TOKENS);
        }

        return [
            'draft' => $draft,
            'source' => 'first-contact:mock-llm:'.$decision->reason,
            'confidence' => self::MOCK_CONFIDENCE,
            'quota_verdict' => $verdict->toArray(),
        ];
    }

    /**
     * Mock LLM path: детерминированный черновик первого ответа. Никаких
     * реальных LLM-вызовов, никаких платных API — только шаблон на основе
     * замаскированного входа и контекста маршрутизации.
     *
     * Регистр ответа — домашний (H1876): обращение на «вы» со строчной буквы,
     * тон спокойный, без эмодзи, «ё» не используется, часовой пояс «МСК».
     */
    private function mockLlmDraft(string $maskedText, W1RoutingDecision $decision): string
    {
        return 'Здравствуйте! Спасибо за обращение. '
            .'Мы получили ваш вопрос и готовы помочь. '
            .'Ваш куратор свяжется с вами в ближайшее время с подробным ответом. '
            .'Если у вас есть дополнительные вопросы, не стесняйтесь спрашивать.';
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
}
