<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Services\Support\Hermes\Access\W1AccessDiagnosticsResult;
use App\Services\Support\Hermes\Access\W1AccessDiagnosticsService;
use App\Services\Support\Hermes\Access\W1AccessFixProposal;
use App\Services\Support\Hermes\GoldenCaseEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\Concerns\H6189AccessDiagnosticsScenarios;
use Tests\TestCase;

/**
 * H6189 acceptance (Epic E026, Hermes W1): access diagnostics — роль 85
 * `access-provisioner` (диагностика и предложение исправления доступа).
 *
 * Обёртка над GoldenCaseEvaluator H6181 (role_85_access_provisioner.json
 * против живого W1AccessDiagnosticsService) плюс сценарии из
 * Concerns\H6189AccessDiagnosticsScenarios: четыре состояния права
 * (действующее/истёкшее/чужое/отсутствующее), объяснение основания, проект
 * операции как ДАННЫЕ для человека (НИКАКИХ реальных изменений доступов —
 * access mutations запрещены абсолютно), PII fail-closed (H6182),
 * маршрутизация через общий слой H6190 (запрещённые контуры → отказ, массовый
 * инцидент → эскалация), quota-aware (H6174, default OFF — log-only), роль
 * OFF по умолчанию (W1 deploy gate), без исходящих отправок и
 * production-включения. Фикстуры синтетические (provenance H6181);
 * прод-проб нет.
 */
class H6189HermesAcceptanceTest extends TestCase
{
    use H6189AccessDiagnosticsScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Роль 85 (H6189) — flag ON только в тестовом контексте; в проде
        // флаг ВЫКЛ (role 85 stays OFF, W1 deploy gate).
        config(['features.hermes_access_diagnostics' => true]);
        // Роль 23 исполняется в общем golden-прогоне (как в H6181/H6184/H6190).
        config(['features.hermes_schedule_keeper' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Обёртка над существующим валидатором: golden-набор H6181 валиден,
     * роль 85 исполняется живьём (4/4), роль 23 не сломана, правило
     * «ошибки в знаменателе» живо.
     */
    public function test_wrapper_existing_golden_validator_stays_green(): void
    {
        $service = new W1AccessDiagnosticsService;
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);

        // Регистрируем раннер роли 85 — вызывает access diagnostics сервис.
        $evaluator->registerRunner(85, function (array $input) use ($service): array {
            return $service->handle(
                'h6189:golden:'.md5((string) json_encode($input)),
                $input,
            )->toObservation();
        });

        $report = $evaluator->evaluate();

        $this->assertSame('hermes-w1-golden', $report['set']);
        $this->assertSame(1, $report['set_version']);
        $this->assertSame('H6181', $report['handoff']);

        // Роль 85: 4 golden-кейса, все исполнены и пройдены.
        $role85 = $report['roles']['85'];
        $this->assertSame('implemented', $role85['implementation_status'], 'role 85: implementation landed');
        $this->assertSame('evaluated', $role85['status'], 'role 85: implemented and evaluated');
        $this->assertSame(4, $role85['executed'], 'role 85: 4 golden cases executed');
        $this->assertSame(4, $role85['passed'], 'role 85: all cases pass');
        $this->assertSame(0, $role85['errors'], 'role 85: no errors');
        $this->assertSame(0, $role85['failed'], 'role 85: no failures');

        // Роль 23 остаётся зелёной (не сломана).
        $this->assertSame(4, $report['roles']['23']['executed']);
        $this->assertSame(4, $report['roles']['23']['passed']);
        $this->assertSame(0, $report['roles']['23']['errors']);

        $this->assertTrue($report['score']['errors_in_denominator']);
        $this->assertNotContains(85, $report['inconclusive'], 'role 85: реализована — не INCONCLUSIVE');
    }

    /**
     * Golden-фикстура ap-001..ap-004: каждый версионированный ключ expected
     * сверяется с наблюдением (H6190-паттерн) — outcome, route_to_human,
     * proposal_requires_human_approval, no_auto_grant, must_not_auto_revoke,
     * forbidden_contour, mass_incident.
     */
    public function test_golden_fixture_cases_match_full_versioned_expected_contract(): void
    {
        $fixture = json_decode((string) file_get_contents(
            base_path(GoldenCaseEvaluator::FIXTURE_DIR.'/role_85_access_provisioner.json'),
        ), true);

        $this->assertTrue($fixture['provenance']['synthetic'], 'role 85 fixture: synthetic provenance');
        $this->assertFalse($fixture['provenance']['student_dialogues_copied'], 'role 85 fixture: no copied dialogues');
        $this->assertSame('implemented', $fixture['implementation_status'], 'role 85: implementation landed');

        $service = new W1AccessDiagnosticsService;

        foreach ($fixture['cases'] as $case) {
            $this->assertTrue($case['synthetic'], "{$case['id']}: synthetic case");

            $result = $service->handle('h6189:golden-direct:'.$case['id'], (array) $case['input']);
            $expected = $case['expected'];

            $this->assertSame(
                $expected['outcome'],
                $result->outcome,
                "{$case['id']}: outcome must match the versioned expected (".json_encode($expected, JSON_UNESCAPED_UNICODE).')',
            );
            $this->assertContains($result->outcome, W1AccessDiagnosticsResult::OUTCOMES, "{$case['id']}: canonical outcome");

            if (array_key_exists('route_to_human', $expected)) {
                $this->assertSame((bool) $expected['route_to_human'], $result->routeToHuman, "{$case['id']}: route_to_human");
            }
            if (($expected['proposal_requires_human_approval'] ?? false) === true) {
                $this->assertNotNull($result->proposal, "{$case['id']}: proposal required");
                $this->assertTrue($result->proposal->requiresHumanApproval, "{$case['id']}: proposal_requires_human_approval");
            }
            if (($expected['no_auto_grant'] ?? false) === true) {
                $this->assertNotNull($result->proposal, "{$case['id']}: proposal required for no_auto_grant check");
                $this->assertFalse($result->proposal->autoApplied, "{$case['id']}: no_auto_grant — операция никогда не применяется");
            }
            if (($expected['must_not_auto_revoke'] ?? false) === true) {
                $this->assertTrue($result->mustNotAutoRevoke, "{$case['id']}: must_not_auto_revoke");
                $this->assertNotSame(W1AccessFixProposal::OP_REVOKE, $result->proposal?->operation, "{$case['id']}: никакого проекта отзыва");
            }
            if (isset($expected['forbidden_contour'])) {
                $this->assertSame($expected['forbidden_contour'], $result->forbiddenContour, "{$case['id']}: forbidden_contour");
            }
            if (($expected['mass_incident'] ?? false) === true) {
                $this->assertTrue($result->massIncident, "{$case['id']}: mass_incident");
            }
        }
    }

    /** Quota OFF (default): log-only, черновик собирается без enforcement. */
    public function test_quota_machine_default_off_log_only(): void
    {
        $this->assertFalse(config('school_role_quota.enabled', false), 'квотная машина H6174 — OFF по умолчанию');

        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:quota-off', ['scenario' => self::SCENARIO_MISSING_CONFIRMED]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_DRAFTED, $result->outcome, 'quota OFF: черновик собирается');
        $this->assertNotNull($result->draft);
        $this->assertIsArray($result->quotaInfo, 'снимок квоты присутствует (log-only)');
        $this->assertArrayHasKey('enabled', $result->quotaInfo);
        $this->assertFalse($result->quotaInfo['enabled'], 'квотная машина выключена');
    }

    /**
     * НИКАКИХ реальных изменений доступов: все пути диагностики (включая
     * проекты grant/extend/revoke/rebind) не трогают ни одну таблицу;
     * обещанные сроки записей сохраняются.
     */
    public function test_no_access_mutations_zero_side_effects(): void
    {
        User::factory()->create(); // нетривиальный снапшот: таблицы не пусты

        $before = $this->tableCounts();
        $beforeDeadlines = $this->promisedDeadlineSnapshot();

        $service = new W1AccessDiagnosticsService;
        $scenarios = [
            ['payment' => ['status' => 'confirmed_paid'], 'access' => ['course_access' => false]],                    // проект grant
            ['payment' => ['status' => 'confirmed_paid'], 'access' => ['course_access' => true]],                     // проект none
            ['payment' => ['status' => 'confirmed_paid'], 'access' => ['course_access' => true, 'expires_at' => '2026-01-01 00:00:00']], // проект extend
            ['payment' => ['status' => 'pending_disputed'], 'access' => ['course_access' => true]],                   // никакого отзыва
            ['payment' => ['status' => 'pending'], 'access' => ['course_access' => true]],                            // проект revoke (только данные)
            ['payment' => ['status' => 'confirmed_paid'], 'access' => ['course_access' => true, 'owner_matches_student' => false]], // проект rebind
            ['payment' => ['status' => 'none'], 'access' => ['course_access' => false]],                              // отказ без операции
        ];

        foreach ($scenarios as $i => $scenario) {
            $result = $service->handle('h6189:nse:'.$i, ['scenario' => $scenario]);

            if ($result->proposal !== null) {
                $this->assertTrue($result->proposal->isDataOnly(), "сценарий {$i}: проект — только данные для человека");
                $this->assertFalse($result->proposal->autoApplied, "сценарий {$i}: операция не применена");
                $this->assertTrue($result->proposal->preservePromisedDeadlines, "сценарий {$i}: обещанные сроки записей сохраняются");
            }
        }

        $this->assertSame($before, $this->tableCounts(), 'диагностика доступа не трогает ни одну таблицу (no side-effects)');
        $this->assertSame($beforeDeadlines, $this->promisedDeadlineSnapshot(), 'обещанные сроки записей не сдвинуты');
    }

    /** Роль 85 OFF по умолчанию (W1 deploy gate): ни диагностики, ни проектов. */
    public function test_role_off_by_default_feature_flag_closed(): void
    {
        // Дефолт конфига: env HERMES_ACCESS_DIAGNOSTICS не задан → false.
        config(['features.hermes_access_diagnostics' => (bool) env('HERMES_ACCESS_DIAGNOSTICS', false)]);
        $this->assertFalse(config('features.hermes_access_diagnostics', false), 'роль 85 OFF по умолчанию');

        $service = new W1AccessDiagnosticsService;
        $this->assertFalse($service->isEnabled());

        $result = $service->handle('h6189:test:role-off', ['scenario' => self::SCENARIO_MISSING_CONFIRMED]);

        $this->assertSame(W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN, $result->outcome);
        $this->assertSame('role_disabled', $result->routingReason);
        $this->assertNull($result->proposal, 'роль OFF: никаких проектов');
        $this->assertNull($result->draft);
    }

    /** Повторные события → одно назначение (H6190 in-memory ledger), без дублей. */
    public function test_repeated_events_create_no_duplicate_assignments(): void
    {
        $service = new W1AccessDiagnosticsService;

        $first = $service->handle('h6189:dup:1', [
            'message' => 'Откройте мне доступ к курсу, я заплачу попозже, честное слово.',
        ]);
        $second = $service->handle('h6189:dup:1', [
            'message' => 'Откройте мне доступ к курсу, я заплачу попозже, честное слово.',
        ]);

        $this->assertSame($first->outcome, $second->outcome, 'то же событие: тот же исход (без дублей)');
        $this->assertSame($first->route, $second->route);
        $this->assertCount(1, $service->routingLayer()->ledger()->assignments(), 'одно назначение на событие');
    }

    /** toObservation() → GoldenCaseEvaluator контракт (outcome/draft/flags). */
    public function test_observation_contract_matches_golden_evaluator(): void
    {
        $service = new W1AccessDiagnosticsService;
        $result = $service->handle('h6189:test:obs', ['scenario' => self::SCENARIO_MISSING_CONFIRMED]);

        $observation = $result->toObservation();

        $this->assertArrayHasKey('outcome', $observation);
        $this->assertArrayHasKey('draft', $observation);
        $this->assertArrayHasKey('requires_human_verification', $observation);
        $this->assertArrayHasKey('no_side_effects', $observation);
        $this->assertTrue($observation['no_side_effects'], 'наблюдение декларирует отсутствие side-effects');
    }

    /** Только канонические маршруты ростера V2, никакой седьмой роли. */
    public function test_only_canonical_route_targets_no_new_role_ids(): void
    {
        $service = new W1AccessDiagnosticsService;
        $routes = [];

        foreach ([self::SCENARIO_MISSING_CONFIRMED, ['payment' => ['status' => 'pending'], 'access' => ['course_access' => true]]] as $i => $scenario) {
            $routes[] = $service->handle('h6189:routes:'.$i, ['scenario' => $scenario])->route;
        }

        foreach ($routes as $route) {
            $this->assertContains($route, GoldenCaseEvaluator::ROUTE_TARGETS, 'маршрут обязан быть канонической целью V2');
        }
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? (string) ($table['name'] ?? '') : (string) $table;
            if ($name !== '') {
                $counts[$name] = DB::table($name)->count();
            }
        }
        ksort($counts);

        return $counts;
    }

    /** Снапшот обещанных сроков записей: max(updated_at) денежных/доступных таблиц. */
    private function promisedDeadlineSnapshot(): array
    {
        $snapshot = [];
        foreach (['payments', 'course_user', 'group_user'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'updated_at')) {
                $snapshot[$table] = DB::table($table)->max('updated_at');
            }
        }

        return $snapshot;
    }
}
