<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Concerns;

use App\Services\Support\Hermes\Access\W1AccessDiagnosticsResult;
use App\Services\Support\Hermes\Access\W1AccessDiagnosticsService;
use App\Services\Support\Hermes\Access\W1AccessFixProposal;
use App\Services\Support\Hermes\Routing\W1RoutingDecision;
use App\Services\Support\SupportStudentPiiMasker;

/**
 * H6189 сценарии диагностики доступа (роль 85): четыре состояния права
 * (действующее/истёкшее/чужое/отсутствующее), объяснение основания, проект
 * операции как ДАННЫЕ (никаких реальных изменений доступов), спорная оплата
 * без автоотзыва, запрещённые контуры H6190, массовый инцидент, PII и
 * fail-closed (H6182), квота ON (H6174). Часть H6189HermesAcceptanceTest.
 */
trait H6189AccessDiagnosticsScenarios
{
    /** Синтетический снимок «оплата подтверждена, права нет» (ap-001). */
    private const SCENARIO_MISSING_CONFIRMED = [
        'payment' => ['status' => 'confirmed_paid'],
        'access' => ['course_access' => false],
    ];

    /** ap-001: отсутствующее право + подтверждённая оплата → проект выдачи (данные). */
    public function test_missing_access_with_confirmed_payment_proposes_grant_as_data(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:grant', ['scenario' => self::SCENARIO_MISSING_CONFIRMED]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertSame(W1AccessDiagnosticsResult::STATE_MISSING, $result->accessState, 'отсутствующее право');
        $this->assertSame(W1AccessDiagnosticsResult::BASIS_CONFIRMED, $result->basis);
        $this->assertStringContainsString('подтверждённая оплата', $result->basisExplanation, 'объяснение основания');
        $this->assertFalse($result->routeToHuman);

        $this->assertNotNull($result->proposal, 'проект операции выдачи — данные для человека');
        $this->assertSame(W1AccessFixProposal::OP_GRANT, $result->proposal->operation);
        $this->assertTrue($result->proposal->requiresHumanApproval, 'исполнение — только подтверждение человеком');
        $this->assertFalse($result->proposal->autoApplied, 'НИКАКИХ реальных изменений доступов');
        $this->assertTrue($result->proposal->isDataOnly());

        $this->assertNotNull($result->draft, 'диагноз + проект операции собираются в черновик');
        $this->assertStringContainsString('выдача права', $result->draft);
        $this->assertStringContainsString('только подтверждение человека', $result->draft);
    }

    /** Истёкшее право + подтверждённая оплата → проект продления, сроки сохраняются. */
    public function test_expired_access_proposes_extend_preserving_promised_deadlines(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:extend', ['scenario' => [
            'payment' => ['status' => 'confirmed_paid'],
            'access' => ['course_access' => true, 'expires_at' => '2026-01-01 00:00:00'],
        ]]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertSame(W1AccessDiagnosticsResult::STATE_EXPIRED, $result->accessState, 'истёкшее право');
        $this->assertNotNull($result->proposal);
        $this->assertSame(W1AccessFixProposal::OP_EXTEND, $result->proposal->operation);
        $this->assertTrue($result->proposal->preservePromisedDeadlines, 'сохранять обещанные сроки записей');
        $this->assertFalse($result->proposal->autoApplied);
    }

    /** Чужое право → человеку; проект перепривязки, владелец не раскрывается (PII). */
    public function test_foreign_access_requires_human_rebind_proposal_without_owner_pii(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:foreign', ['scenario' => [
            'payment' => ['status' => 'confirmed_paid'],
            'access' => ['course_access' => true, 'owner_matches_student' => false],
        ]]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN, $result->outcome);
        $this->assertSame(W1AccessDiagnosticsResult::STATE_FOREIGN, $result->accessState, 'чужое право');
        $this->assertTrue($result->routeToHuman);
        $this->assertNotNull($result->proposal);
        $this->assertSame(W1AccessFixProposal::OP_REBIND, $result->proposal->operation, 'перепривязка — только проект');
        $this->assertTrue($result->proposal->requiresHumanApproval);
        $this->assertFalse($result->proposal->autoApplied);

        // PII: никаких имён/контактов владельца в диагнозе и проекте.
        $haystack = (string) $result->draft.' '.$result->basisExplanation.' '.$result->proposal->basis;
        $this->assertStringNotContainsString('owner', $haystack, 'владелец не называется');
    }

    /** Действующее право + подтверждённая оплата → «менять нечего». */
    public function test_active_access_with_confirmed_payment_reports_no_operation(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:active-ok', ['scenario' => [
            'payment' => ['status' => 'confirmed_paid'],
            'access' => ['course_access' => true],
        ]]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertSame(W1AccessDiagnosticsResult::STATE_ACTIVE, $result->accessState, 'действующее право');
        $this->assertNotNull($result->proposal);
        $this->assertSame(W1AccessFixProposal::OP_NONE, $result->proposal->operation, 'менять нечего');
    }

    /** ap-002: спорная оплата при действующем праве → никакого автоотзыва. */
    public function test_disputed_payment_never_auto_revokes_active_access(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:disputed', ['scenario' => [
            'payment' => ['status' => 'pending_disputed'],
            'access' => ['course_access' => true],
        ]]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN, $result->outcome);
        $this->assertSame(W1AccessDiagnosticsResult::BASIS_DISPUTED, $result->basis, 'спорная оплата');
        $this->assertTrue($result->routeToHuman);
        $this->assertTrue($result->mustNotAutoRevoke, 'спорная оплата — не повод автоматического отзыва доступа');
        $this->assertNotNull($result->proposal);
        $this->assertNotSame(W1AccessFixProposal::OP_REVOKE, $result->proposal->operation);
        $this->assertFalse($result->proposal->autoApplied);
    }

    /** Неподтверждённая оплата → отказ: роль не предлагает операцию без основания. */
    public function test_missing_access_without_confirmed_payment_refuses_operation(): void
    {
        $service = new W1AccessDiagnosticsService;

        foreach (['pending', 'failed', 'none'] as $status) {
            $result = $service->handle('h6189:test:no-basis:'.$status, ['scenario' => [
                'payment' => ['status' => $status],
                'access' => ['course_access' => false],
            ]]);

            $this->assertSame(
                W1AccessDiagnosticsResult::OUTCOME_REFUSED,
                $result->outcome,
                "{$status}: неподтверждённая оплата — не основание для выдачи",
            );
            $this->assertNotNull($result->proposal);
            $this->assertSame(W1AccessFixProposal::OP_NONE, $result->proposal->operation, 'операция не предлагается');
            $this->assertTrue($result->routeToHuman);
        }
    }

    /** ap-003: требование выдачи доступа без основания → отказ без проекта (H6190 forbidden). */
    public function test_forbidden_access_demand_refused_without_proposal(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:forbidden', [
            'message' => 'Откройте мне доступ к курсу, я заплачу попозже, честное слово.',
            'context' => ['channel' => 'tg', 'author' => 'student'],
        ]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_REFUSED, $result->outcome);
        $this->assertSame(W1RoutingDecision::CATEGORY_FORBIDDEN, $result->routingCategory, 'контур-гейт H6190');
        $this->assertSame('access', $result->forbiddenContour);
        $this->assertTrue($result->routeToHuman);
        $this->assertNull($result->proposal, 'роль не предлагает операцию без основания');
        $this->assertNull($result->draft, 'forbidden: без черновика');
    }

    /** ap-004: массовый инцидент → прямая эскалация человеку/инфраструктуре. */
    public function test_mass_incident_escalates_directly_to_human(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:mass', [
            'message' => 'У всей нашей группы пропал доступ к занятиям, что происходит?',
            'context' => ['channel' => 'tg', 'author' => 'student', 'mass_incident' => true],
        ]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_ESCALATED, $result->outcome);
        $this->assertTrue($result->massIncident, 'массовая недоступность — не одиночная диагностика');
        $this->assertTrue($result->routeToHuman);
        $this->assertSame('human', $result->route);
        $this->assertNull($result->proposal);
        $this->assertNull($result->draft);
    }

    /** PII (H6182): имена/e-mail/телефоны не утекают ни в диагноз, ни в проект. */
    public function test_pii_never_leaks_into_diagnostics_proposal_or_draft(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:pii', [
            'message' => 'Меня зовут Анна Петрова, мой email anna.petrova@example.com, '
                .'телефон +7 (916) 123-45-67. Хочу записаться на курс.',
            'context' => ['channel' => 'tg', 'author' => 'student'],
            'scenario' => self::SCENARIO_MISSING_CONFIRMED,
        ]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertGreaterThan(0, $result->piiMaskCount, 'PII-подстановки считаются');

        $haystack = (string) $result->draft.' '.$result->basisExplanation.' '
            .(string) $result->maskedRequest.' '.json_encode($result->proposal, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Анна Петрова', $haystack, 'имя не утекает');
        $this->assertStringNotContainsString('anna.petrova@example.com', $haystack, 'e-mail не утекает');
        $this->assertStringNotContainsString('+7 (916) 123-45-67', $haystack, 'телефон не утекает');
        $this->assertNotNull($result->maskedRequest);
        $this->assertStringContainsString('<PII:', $result->maskedRequest, 'запрос хранится только замаскированным');
    }

    /** PII fail-closed (H6182): сбой маскера → текст исключён полностью. */
    public function test_pii_fail_closed_excludes_text_on_masker_failure(): void
    {
        $masker = new class extends SupportStudentPiiMasker
        {
            public function maskOrFail(string $text): array
            {
                throw new \RuntimeException('identity-net unavailable (simulated)');
            }
        };

        $service = new W1AccessDiagnosticsService(piiMasker: $masker);

        // Со снимком состояния: диагностика идёт из структурных данных,
        // текст исключён полностью.
        $result = $service->handle('h6189:test:pii-failclosed-scenario', [
            'message' => 'Меня зовут Иван Петров, откройте доступ.',
            'scenario' => self::SCENARIO_MISSING_CONFIRMED,
        ]);
        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertNull($result->maskedRequest, 'fail-closed: текст исключён');
        $this->assertSame(0, $result->piiMaskCount);
        $haystack = (string) $result->draft.' '.$result->basisExplanation;
        $this->assertStringNotContainsString('Иван Петров', $haystack);

        // Без снимка состояния: обрабатывать нечего — человеку.
        $closed = $service->handle('h6189:test:pii-failclosed-message', [
            'message' => 'Меня зовут Иван Петров, откройте доступ.',
        ]);
        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN, $closed->outcome);
        $this->assertSame('pii_boundary_closed', $closed->routingReason);
        $this->assertNull($closed->maskedRequest);
    }

    /** Quota ON + исчерпана: requires_human (fallback=none), проект остаётся данными. */
    public function test_quota_machine_denied_when_enabled_and_exhausted(): void
    {
        config([
            'school_role_quota.enabled' => true,
            'school_role_quota.allowances' => [W1AccessDiagnosticsService::ROLE_SLUG => 50],
        ]);

        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:quota-denied', ['scenario' => self::SCENARIO_MISSING_CONFIRMED]);

        $this->assertSame(
            W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
            $result->outcome,
            'квота исчерпана: очередь человеку (fallback=none)',
        );
        $this->assertNull($result->draft, 'квота отказала: ноль генерации');
        $this->assertNotNull($result->proposal, 'диагностика и проект остаются данными');
        $this->assertTrue($result->proposal->isDataOnly());
    }
}
