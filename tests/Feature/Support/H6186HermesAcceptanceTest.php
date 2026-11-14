<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Hermes\FirstContact\W1FirstContactService;
use App\Services\Support\Hermes\GoldenCaseEvaluator;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepAction;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepResult;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\Concerns\H6186BindingPrepScenarios;
use Tests\Feature\Support\Concerns\H6186BindingSafetyScenarios;
use Tests\TestCase;

/**
 * H6186 acceptance (Epic E026, Hermes W1): роль 19 `onboarding-greeter` —
 * безопасная подготовка привязки к боту. Обёртка над GoldenCaseEvaluator
 * H6181 (role_19_onboarding.json) + сценарии сервиса подготовки
 * (H6186BindingPrepScenarios / H6186BindingSafetyScenarios):
 * принадлежность аккаунта, чужой account, истёкший token и replay; действие
 * человеку, привязка НИКОГДА не применяется автоматически; deep-link/токен —
 * только данные; reusable token не попадает в черновик (LLM) и не попадает в
 * отчёт; PII-masked (H6182 fail-closed); эскалация по runbook H6133;
 * quota-aware default OFF (H6174 log-only); маршрутизация через общий слой
 * H6190; без production-включения и без исходящих отправок (human-send per
 * H6183). Fixtures синтетические (provenance H6181), прод-проб нет.
 */
class H6186HermesAcceptanceTest extends TestCase
{
    use H6186BindingPrepScenarios;
    use H6186BindingSafetyScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.hermes_schedule_keeper' => true,
            'services.telegram.student_bot_username' => 'samskrtamru_bot',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Обёртка над GoldenCaseEvaluator: роль 19 исполняется живьём, роли 13 и
     * 23 зелёные, нереализованные роли остаются честным INCONCLUSIVE
     * (pending_implementation), ошибки в знаменателе.
     */
    public function test_wrapper_existing_golden_validator_stays_green(): void
    {
        $onboarding = new W1BotBindingPrepService;
        $firstContact = new W1FirstContactService;
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);

        // Раннер роли 19 — сервис подготовки привязки (H6186).
        $evaluator->registerRunner(19, function (array $input) use ($onboarding): array {
            $result = $onboarding->handle(
                'h6186:golden:role19:'.md5((string) json_encode($input)),
                (string) $input['message'],
                (array) ($input['context'] ?? []),
            );

            return $result->toObservation();
        });

        // Раннер роли 13 — существующий first-contact сервис (H6184), чтобы
        // прогон был сквозным и соседние роли остались зелёными.
        $evaluator->registerRunner(13, function (array $input) use ($firstContact): array {
            $result = $firstContact->handle(
                'h6186:golden:role13:'.md5((string) json_encode($input)),
                (string) $input['message'],
                (array) ($input['context'] ?? []),
            );

            return $result->toObservation();
        });

        $report = $evaluator->evaluate();

        $this->assertSame('hermes-w1-golden', $report['set']);
        $this->assertSame(1, $report['set_version']);

        // Роль 19: 4 golden-кейса, все исполнены и пройдены.
        $this->assertSame(4, $report['roles']['19']['executed'], 'role 19: 4 golden cases executed');
        $this->assertSame(4, $report['roles']['19']['passed'], 'role 19: all cases pass');
        $this->assertSame(0, $report['roles']['19']['errors'], 'role 19: no errors');
        $this->assertSame('evaluated', $report['roles']['19']['status'], 'role 19: implemented and evaluated');

        // Соседи не сломаны: роль 13 (H6184) и роль 23 (H6187) зелёные.
        $this->assertSame(4, $report['roles']['13']['executed']);
        $this->assertSame(4, $report['roles']['13']['passed']);
        $this->assertSame(0, $report['roles']['13']['errors']);
        $this->assertSame(4, $report['roles']['23']['executed']);
        $this->assertSame(4, $report['roles']['23']['passed']);
        $this->assertSame(0, $report['roles']['23']['errors']);

        // Нереализованные роли (20/65/85) и routing — честный INCONCLUSIVE,
        // никогда не выдуманный PASS (правило H6181).
        foreach (['20', '65', '85'] as $roleId) {
            $this->assertSame('pending_implementation', $report['roles'][$roleId]['status'], "role {$roleId}: honest pending");
            $this->assertSame(0, $report['roles'][$roleId]['executed'], "role {$roleId}: nothing executed, nothing invented");
        }
        $this->assertContains(20, $report['inconclusive']);
        $this->assertContains(65, $report['inconclusive']);
        $this->assertContains(85, $report['inconclusive']);
        $this->assertNotContains(19, $report['inconclusive'], 'role 19: honestly evaluated, not inconclusive');

        // Правило «ошибки в знаменателе» живо; 12 исполненных кейсов зелёные.
        $this->assertTrue($report['score']['errors_in_denominator']);
        $this->assertSame(12, $report['score']['executed'], 'roles 13+19+23: 4 cases each');
        $this->assertSame(12, $report['score']['passed']);
        $this->assertSame(1.0, $report['score']['pass_rate']);
    }

    /**
     * Golden-фикстура H6181: ob-001..ob-004 → ровно те исходы, что в expected
     * (outcome + route_to_human + human_send_required +
     * steps_from_approved_checklist_only + no_confident_guess +
     * forbidden_contour).
     */
    public function test_golden_fixture_cases_match_expected_outcomes(): void
    {
        $fixture = json_decode((string) file_get_contents(
            base_path(GoldenCaseEvaluator::FIXTURE_DIR.'/role_19_onboarding.json'),
        ), true);

        $this->assertTrue($fixture['provenance']['synthetic'], 'role 19 fixture: synthetic provenance');
        $this->assertFalse($fixture['provenance']['student_dialogues_copied'], 'role 19 fixture: no copied dialogues');
        $this->assertSame('implemented', $fixture['implementation_status'], 'role 19: implementation landed');

        $service = new W1BotBindingPrepService;

        foreach ($fixture['cases'] as $case) {
            $result = $service->handle(
                'h6186:golden-direct:'.$case['id'],
                (string) $case['input']['message'],
                (array) ($case['input']['context'] ?? []),
            );

            $expected = $case['expected'];

            $this->assertSame(
                $expected['outcome'],
                $result->outcome,
                "{$case['id']}: outcome must match the versioned expected",
            );
            $this->assertSame(
                (bool) $expected['route_to_human'],
                $result->routeToHuman,
                "{$case['id']}: route_to_human",
            );

            if (array_key_exists('human_send_required', $expected)) {
                $this->assertSame((bool) $expected['human_send_required'], $result->humanSendRequired, "{$case['id']}: human_send_required");
            }
            if (array_key_exists('steps_from_approved_checklist_only', $expected)) {
                $this->assertSame(
                    (bool) $expected['steps_from_approved_checklist_only'],
                    $result->stepsFromApprovedChecklistOnly,
                    "{$case['id']}: steps_from_approved_checklist_only",
                );
            }
            if (array_key_exists('no_confident_guess', $expected)) {
                $this->assertSame((bool) $expected['no_confident_guess'], $result->noConfidentGuess, "{$case['id']}: no_confident_guess");
            }
            if (array_key_exists('forbidden_contour', $expected)) {
                $this->assertSame($expected['forbidden_contour'], $result->forbiddenContour, "{$case['id']}: forbidden_contour");
            }

            $this->assertContains($result->outcome, W1BotBindingPrepResult::OUTCOMES, "{$case['id']}: canonical outcome");

            // Инварианты миссии на каждом кейсе: без применения и без отправок.
            $this->assertFalse(
                ($result->action !== null) && W1BotBindingPrepAction::APPLIED,
                "{$case['id']}: binding is never applied automatically",
            );
        }
    }
}
