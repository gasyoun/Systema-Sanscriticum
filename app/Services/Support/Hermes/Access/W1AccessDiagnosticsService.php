<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Access;

use App\Services\Support\Hermes\Quota\QuotaLane;
use App\Services\Support\Hermes\Quota\SchoolRoleQuotaService;
use App\Services\Support\Hermes\Routing\W1FirstContactRoutingLayer;
use App\Services\Support\Hermes\Routing\W1RoutingDecision;
use App\Services\Support\SupportStudentPiiMasker;

/**
 * H6189 (Epic E026, Hermes W1): access diagnostics — роль 85
 * `access-provisioner` (диагностика и предложение исправления доступа).
 *
 * READ-ONLY диагностика + проект исправления: проверить действующее/
 * истёкшее/чужое/отсутствующее право; объяснить основание и предложить
 * исправление человеку. НИКАКИХ реальных изменений доступов (exclusions
 * миссии: «access mutations запрещены абсолютно») — сервис не имеет
 * write-пути и не читает БД: состояние права приходит машиночитаемым
 * снимком (синтетика по умолчанию), прод-проб нет. Таблица решений —
 * {@see W1AccessDecisionTable}.
 *
 * Потребитель общих слоёв W1 («расширять существующее, не параллельный
 * механизм»):
 *  - H6190 Routing (W1FirstContactRoutingLayer) — маршрутизация первого
 *    контакта: запрещённые контуры/injection → отказ, триггеры runbook
 *    H6133 → прямая эскалация; один проход, без циклов и дублей;
 *  - H6182 PII (SupportStudentPiiMasker) — fail-closed: вход маскируется ДО
 *    обработки; сбой маскера исключает текст полностью («чистоту нельзя
 *    доказать → текста нет»); PII не достигает LLM (LLM-вызовов нет вообще);
 *  - H6174 Quota (SchoolRoleQuotaService, default OFF) — квотная машина
 *    ограничивает композицию черновика; структурная диагностика и проект —
 *    данные; при отказе квоты — очередь человеку (fallback=none);
 *  - H6181 Golden (GoldenCaseEvaluator) — раннер роли 85 регистрируется
 *    снаружи через registerRunner(); наблюдение — {@see
 *    W1AccessDiagnosticsResult::toObservation()}.
 *
 * Границы (exclusions миссии H6189): роль OFF по умолчанию
 * (features.hermes_access_diagnostics, включение — deploy-шаг человека),
 * НЕТ production-включения, исходящих отправок (human-send per H6183),
 * платных API, реальных LLM-вызовов (mock/synthetic), финансовых мутаций и
 * новых канонических role ID.
 */
final class W1AccessDiagnosticsService
{
    /** Канонический role ID роли 85 ростера V2 (Uprava docs/FLEET_…_ROSTER_GRILL_04-10-2026.md). */
    public const ROLE_ID = 85;

    /** Канонический slug роли 85 ростера V2 (role_name golden-фикстуры H6181). */
    public const ROLE_SLUG = 'access-provisioner';

    /** Оценка токенов композиции черновика (mock path) для квоты H6174. */
    private const ESTIMATED_TOKENS = 80;

    /** Уровень уверенности mock-черновика (детерминированный шаблон). */
    private const MOCK_CONFIDENCE = 0.85;

    /** Причины закрытых исходов (машиночитаемые). */
    private const REASON_ROLE_DISABLED = 'role_disabled';

    private const REASON_PII_BOUNDARY_CLOSED = 'pii_boundary_closed';

    private const REASON_STATE_UNAVAILABLE = 'state_unavailable_no_data';

    public function __construct(
        private readonly W1FirstContactRoutingLayer $routingLayer = new W1FirstContactRoutingLayer,
        private readonly SupportStudentPiiMasker $piiMasker = new SupportStudentPiiMasker,
        private readonly SchoolRoleQuotaService $quotaService = new SchoolRoleQuotaService,
        private readonly W1AccessDecisionTable $decisionTable = new W1AccessDecisionTable,
    ) {}

    /** Роль 85 OFF по умолчанию (W1 deploy gate): включение — решение человека. */
    public function isEnabled(): bool
    {
        return (bool) config('features.hermes_access_diagnostics', false);
    }

    /** Общий слой маршрутизации H6190 (доступ к реестру назначений — read-only). */
    public function routingLayer(): W1FirstContactRoutingLayer
    {
        return $this->routingLayer;
    }

    /**
     * Диагностика доступа: PII-граница → маршрутизация H6190 → запрещённые
     * контуры/массовый инцидент/эскалация → диагноз состояния + проект
     * операции (только данные) → квота H6174.
     *
     * Вход — машиночитаемый снимок golden-контракта H6181
     * (role_85_access_provisioner.json):
     *  - `message` + `context` — текстовое обращение (канал, автор,
     *    sensitive, mass_incident, author_name/author_handle);
     *  - `scenario` — синтетический снимок состояния: `payment.status`
     *    (confirmed_paid/pending_disputed/pending/failed/none) и `access`
     *    (course_access/expires_at/owner_matches_student).
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(string $eventKey, array $input): W1AccessDiagnosticsResult
    {
        $context = (array) ($input['context'] ?? []);
        $message = trim((string) ($input['message'] ?? ''));
        $scenario = (array) ($input['scenario'] ?? []);

        // 0. Роль OFF (default): ни диагностики, ни проектов — человеку.
        if (! $this->isEnabled()) {
            return W1AccessDiagnosticsResult::closed(
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                self::REASON_ROLE_DISABLED,
                $this->quotaStatusOnly(),
            );
        }

        // 1. PII-граница H6182 (fail-closed): текст маскируется ДО обработки;
        //    сбой маскера исключает текст полностью.
        $maskedRequest = null;
        $piiMaskCount = 0;
        if ($message !== '') {
            try {
                [$maskedRequest, $piiMaskCount] = $this->maskInput($message, $context);
            } catch (\Throwable) {
                $maskedRequest = null;
                $piiMaskCount = 0;
            }
            if ($maskedRequest === '') {
                // Пустой маскированный ответ (H6182): текст исключён из обработки.
                $maskedRequest = null;
                $piiMaskCount = 0;
            }
        }

        // 2. Маршрутизация через общий слой H6190 — по замаскированному тексту
        //    (PII не достигает даже классификатора). Fail-closed при сбое
        //    маскера: без снимка состояния обрабатывать нечего — человеку.
        $decision = null;
        if ($maskedRequest !== null) {
            $decision = $this->routingLayer
                ->routeAndAssign($eventKey, $maskedRequest, $context)
                ->assignment->decision;
        } elseif ($message !== '' && $scenario === []) {
            return W1AccessDiagnosticsResult::closed(
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                self::REASON_PII_BOUNDARY_CLOSED,
                $this->quotaStatusOnly(),
            );
        }

        // 3. Запрещённые контуры H6190 (injection / выдача доступа без
        //    основания): отказ БЕЗ проекта операции — роль не предлагает
        //    операцию без основания (ap-003).
        if ($decision !== null && $decision->category === W1RoutingDecision::CATEGORY_FORBIDDEN) {
            return W1AccessDiagnosticsResult::forbiddenRefusal(
                $decision->reason,
                $decision->category,
                $decision->reason === 'injection_suspected' ? 'injection' : 'access',
                $maskedRequest,
                $piiMaskCount,
                $this->quotaStatusOnly(),
            );
        }

        // 4. Массовый инцидент — прямая эскалация: не одиночная диагностика
        //    (ap-004: «массовая недоступность → человеку/инфраструктуре»).
        if (($context['mass_incident'] ?? false) === true) {
            return W1AccessDiagnosticsResult::escalation(
                true,
                $decision?->category,
                $decision?->reason ?? 'mass_incident',
                'массовый инцидент — не одиночная диагностика: прямая эскалация человеку/инфраструктуре',
                $maskedRequest,
                $piiMaskCount,
                $this->quotaStatusOnly(),
            );
        }

        // 5. Escalation-категория H6190 (триггеры runbook H6133: деньги/
        //    конфликт/тех-сбой) — прямая эскалация человеку.
        if ($decision !== null && $decision->category === W1RoutingDecision::CATEGORY_ESCALATION) {
            return W1AccessDiagnosticsResult::escalation(
                false,
                $decision->category,
                $decision->reason,
                'основание: триггер эскалации runbook ('.$decision->reason.')',
                $maskedRequest,
                $piiMaskCount,
                $this->quotaStatusOnly(),
            );
        }

        // 6. Сценарий состояния — READ-ONLY диагностика + проект операции.
        if ($scenario !== []) {
            return $this->diagnoseScenario($eventKey, $scenario, $maskedRequest, $piiMaskCount);
        }

        // 7. Обращение без машиночитаемого снимка состояния: диагностика
        //    невозможна без данных — без уверенного угадывания, человеку.
        return W1AccessDiagnosticsResult::closed(
            W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
            self::REASON_STATE_UNAVAILABLE,
            $this->quotaStatusOnly(),
            maskedRequest: $maskedRequest,
            piiMaskCount: $piiMaskCount,
        );
    }

    /**
     * PII-маскирование входа по контракту H6182 (fail-closed — исключения
     * обрабатывает вызывающий: текст исключается полностью).
     *
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: int} замаскированный текст и число подстановок
     */
    private function maskInput(string $message, array $context): array
    {
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
    }

    /**
     * READ-ONLY диагностика снимка состояния + проект исправления
     * ({@see W1AccessDecisionTable}: состояние × основание → исход/проект).
     *
     * @param  array<string, mixed>  $scenario
     */
    private function diagnoseScenario(
        string $eventKey,
        array $scenario,
        ?string $maskedRequest,
        int $piiMaskCount,
    ): W1AccessDiagnosticsResult {
        $access = (array) ($scenario['access'] ?? []);
        $state = $this->decisionTable->state(
            (bool) ($access['course_access'] ?? false),
            $access['expires_at'] ?? null,
            (bool) ($access['owner_matches_student'] ?? true),
        );
        [$basis, $basisExplanation] = $this->decisionTable->basis((string) ($scenario['payment']['status'] ?? 'none'));

        if ($state === W1AccessDiagnosticsResult::STATE_UNKNOWN) {
            return W1AccessDiagnosticsResult::closed(
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                'access_state_unparseable',
                $this->quotaStatusOnly(),
                $state,
                $basis,
                $basisExplanation,
                $maskedRequest,
                $piiMaskCount,
            );
        }

        [$outcome, $proposal, $routeToHuman, $mustNotAutoRevoke] = $this->decisionTable->decide($state, $basis, $basisExplanation);

        $draft = null;
        $source = null;
        $confidence = null;
        $quotaInfo = $this->quotaStatusOnly();

        if ($outcome === W1AccessDiagnosticsResult::OUTCOME_DRAFTED) {
            // Квота H6174 (default OFF — log-only) ограничивает композицию
            // черновика; структурная диагностика и проект остаются данными.
            $composition = $this->composeDraftWithQuota($eventKey, $state, $basisExplanation, $proposal);
            $quotaInfo['draft_verdict'] = $composition['quota_verdict'];

            if ($composition['draft'] !== null) {
                $draft = $composition['draft'];
                $source = $composition['source'];
                $confidence = $composition['confidence'];
            } else {
                // Квота отказала (при включённой машине): ноль генерации —
                // в очередь человеку (fallback=none, H6174).
                $outcome = W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN;
                $routeToHuman = true;
            }
        }

        return new W1AccessDiagnosticsResult(
            outcome: $outcome,
            accessState: $state,
            basis: $basis,
            basisExplanation: $basisExplanation,
            proposal: $proposal,
            draft: $draft,
            source: $source,
            confidence: $confidence,
            route: $routeToHuman ? 'human' : 'role:'.self::ROLE_ID,
            routingCategory: null,
            routingReason: 'access_diagnostics',
            routeToHuman: $routeToHuman,
            mustNotAutoRevoke: $mustNotAutoRevoke,
            forbiddenContour: null,
            massIncident: false,
            maskedRequest: $maskedRequest,
            piiMaskCount: $piiMaskCount,
            quotaInfo: $quotaInfo,
        );
    }

    /**
     * Черновик диагностики через квотную машину H6174 (default OFF —
     * log-only): детерминированный шаблон без LLM-вызовов и платных API.
     *
     * @return array{draft: ?string, source: ?string, confidence: ?float, quota_verdict: array<string, mixed>}
     */
    private function composeDraftWithQuota(
        string $eventKey,
        string $state,
        string $basisExplanation,
        ?W1AccessFixProposal $proposal,
    ): array {
        $verdict = $this->quotaService->reserve(
            self::ROLE_SLUG,
            QuotaLane::ROUTINE,
            self::ESTIMATED_TOKENS,
            'h6189:access:'.$eventKey,
            false, // не эксперимент — штатная работа
        );

        if (! $verdict->allowed) {
            // Квота отказала: ноль генерации, платного fallback нет (H6174).
            return ['draft' => null, 'source' => null, 'confidence' => null, 'quota_verdict' => $verdict->toArray()];
        }

        $draft = $this->mockDraft($state, $basisExplanation, $proposal);

        // Фиксация расхода (reservationId === null при disabled — нет записи).
        if ($verdict->reservationId !== null) {
            $this->quotaService->commit($verdict->reservationId, self::ESTIMATED_TOKENS);
        }

        return [
            'draft' => $draft,
            'source' => 'access-diagnostics:mock:'.self::ROLE_SLUG,
            'confidence' => self::MOCK_CONFIDENCE,
            'quota_verdict' => $verdict->toArray(),
        ];
    }

    /**
     * Mock path: детерминированный черновик диагностики (без LLM-вызовов и
     * платных API; PII-вход в черновик не включается вовсе). Регистр —
     * домашний (H1876): «вы» со строчной буквы, спокойный тон, без эмодзи,
     * «ё» не используется.
     */
    private function mockDraft(string $state, string $basisExplanation, ?W1AccessFixProposal $proposal): string
    {
        $stateLabel = match ($state) {
            W1AccessDiagnosticsResult::STATE_ACTIVE => 'действующее право',
            W1AccessDiagnosticsResult::STATE_EXPIRED => 'истёкшее право',
            W1AccessDiagnosticsResult::STATE_FOREIGN => 'чужое право',
            W1AccessDiagnosticsResult::STATE_MISSING => 'право отсутствует',
            default => 'состояние права неизвестно',
        };

        $opLabel = match ($proposal?->operation) {
            W1AccessFixProposal::OP_GRANT => 'выдача права',
            W1AccessFixProposal::OP_EXTEND => 'продление права',
            W1AccessFixProposal::OP_REVOKE => 'отзыв права',
            W1AccessFixProposal::OP_REBIND => 'перепривязка права',
            default => 'операция не предлагается',
        };

        return 'Здравствуйте! Диагностика доступа выполнена в режиме только чтения. '
            ."Состояние права: {$stateLabel}. {$basisExplanation}. "
            ."Проект операции: {$opLabel}. "
            .'Операция не применена: исполнение - только подтверждение человека. '
            .'Обещанные сроки записей сохраняются.';
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
