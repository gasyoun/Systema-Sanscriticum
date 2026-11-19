<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Hermes\Access\W1AccessDiagnosticsService;
use App\Services\Support\Hermes\FaqRag\W1FaqRagGoldenScenarioRunner;
use App\Services\Support\Hermes\FirstContact\W1FirstContactService;
use App\Services\Support\Hermes\GoldenCaseEvaluator;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepService;
use App\Services\Support\Hermes\PaymentReconcile\PaymentReconcileGoldenScenarioRunner;
use App\Services\Support\Hermes\PaymentReconcile\W1PaymentReconcileResult;
use App\Services\Support\Hermes\PaymentReconcile\W1PaymentReconcileService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\Concerns\H6188MoneySafetyScenarios;
use Tests\Feature\Support\Concerns\H6188PaymentReconcileScenarios;
use Tests\TestCase;

/**
 * H6188 acceptance (Epic E026, Hermes W1, класс money): роль 65
 * `payment-reconciler` — READ-ONLY предложение сверки оплат. Обёртка над
 * GoldenCaseEvaluator H6181 (role_65_payment_reconciler.json, runner роли 65
 * живьём; соседние роли 13/19/20/23/85 не сломаны) + сценарии сервиса сверки
 * (H6188PaymentReconcileScenarios / H6188MoneySafetyScenarios):
 * payment/duplicate/refund/mismatch сверяются как ДАННЫЕ, находки — человеку
 * через human-send (H6183); ZERO money-мутаций, ZERO платёжных API и
 * LLM-вызовов; PII fail-closed (H6182); quota-aware default OFF (H6174
 * log-only); роль OFF, без production-включения и исходящих отправок.
 * Fixtures синтетические (provenance H6181), прод-проб нет.
 */
class H6188HermesAcceptanceTest extends TestCase
{
    use H6188MoneySafetyScenarios;
    use H6188PaymentReconcileScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Роль 65 (H6188) — flag ON только в тестовом контексте; в проде
            // флаг ВЫКЛ (role 65 stays OFF, W1 deploy gate).
            'features.hermes_payment_reconciler' => true,
            // Соседние роли в общем golden-прогоне (как в H6185/H6186/H6189).
            'features.hermes_faq_rag' => true,
            'features.hermes_schedule_keeper' => true,
            'features.hermes_access_diagnostics' => true,
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
     * Обёртка над существующим валидатором: golden-набор H6181 валиден, роль
     * 65 исполняется живьём (4/4), соседние роли 13/19/20/23 зелёные,
     * нереализованная роль 85 и routing — честный INCONCLUSIVE
     * (pending_implementation), ошибки в знаменателе.
     */
    public function test_wrapper_existing_golden_validator_stays_green(): void
    {
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);

        // Раннер роли 65 — сервис read-only сверки оплат (H6188).
        $evaluator->registerRunner(65, new PaymentReconcileGoldenScenarioRunner);

        // Раннеры соседних ролей, чтобы прогон был сквозным (H6184/H6185/H6186).
        $firstContact = new W1FirstContactService;
        $evaluator->registerRunner(13, function (array $input) use ($firstContact): array {
            return $firstContact->handle(
                'h6188:golden:role13:'.md5((string) json_encode($input)),
                (string) $input['message'],
                (array) ($input['context'] ?? []),
            )->toObservation();
        });
        $onboarding = new W1BotBindingPrepService;
        $evaluator->registerRunner(19, function (array $input) use ($onboarding): array {
            return $onboarding->handle(
                'h6188:golden:role19:'.md5((string) json_encode($input)),
                (string) $input['message'],
                (array) ($input['context'] ?? []),
            )->toObservation();
        });
        $evaluator->registerRunner(20, new W1FaqRagGoldenScenarioRunner);
        $access = new W1AccessDiagnosticsService;
        $evaluator->registerRunner(85, function (array $input) use ($access): array {
            return $access->handle(
                'h6188:golden:role85:'.md5((string) json_encode($input)),
                $input,
            )->toObservation();
        });

        $report = $evaluator->evaluate();

        $this->assertSame('hermes-w1-golden', $report['set']);
        $this->assertSame(1, $report['set_version']);
        $this->assertSame('H6181', $report['handoff']);

        // Роль 65: 4 golden-кейса, все исполнены и пройдены.
        $role65 = $report['roles']['65'];
        $this->assertSame('implemented', $role65['implementation_status'], 'role 65: implementation landed');
        $this->assertSame('evaluated', $role65['status'], 'role 65: implemented and evaluated');
        $this->assertSame(4, $role65['executed'], 'role 65: 4 golden cases executed');
        $this->assertSame(4, $role65['passed'], 'role 65: all cases pass');
        $this->assertSame(0, $role65['errors'], 'role 65: no errors');
        $this->assertSame(0, $role65['failed'], 'role 65: no failures');

        // Соседи не сломаны: роли 13 (H6184), 19 (H6186), 20 (H6185), 23 (H6187),
        // 85 (H6189).
        foreach (['13', '19', '20', '23', '85'] as $roleId) {
            $this->assertSame(4, $report['roles'][$roleId]['executed'], "role {$roleId}: executed");
            $this->assertSame(4, $report['roles'][$roleId]['passed'], "role {$roleId}: passed");
            $this->assertSame(0, $report['roles'][$roleId]['errors'], "role {$roleId}: no errors");
        }

        // Routing без раннера — честный INCONCLUSIVE (pending_implementation), не PASS.
        $this->assertSame('pending_implementation', $report['routing']['status']);
        $this->assertSame(0, $report['routing']['executed']);
        $this->assertContains('routing', $report['inconclusive']);
        foreach ([13, 19, 20, 23, 65, 85] as $roleId) {
            $this->assertNotContains($roleId, $report['inconclusive'], "role {$roleId}: evaluated, not inconclusive");
        }

        // Правило «ошибки в знаменателе» живо; 24 исполненных кейса зелёные.
        $this->assertTrue($report['score']['errors_in_denominator']);
        $this->assertSame(24, $report['score']['executed'], 'roles 13+19+20+23+65+85: 4 cases each');
        $this->assertSame(24, $report['score']['passed']);
        $this->assertSame(1.0, $report['score']['pass_rate']);
    }

    /**
     * Golden-фикстура H6181: pr-001..pr-004 → ровно те исходы, что в expected
     * (outcome + route_to_human + proposal_requires_human_approval +
     * no_auto_refund + forbidden_contour); инварианты money-класса на каждом
     * кейсе: предложение read-only, без действий и без автоматических возвратов.
     */
    public function test_golden_fixture_cases_match_expected_outcomes(): void
    {
        $fixture = json_decode((string) file_get_contents(
            base_path(GoldenCaseEvaluator::FIXTURE_DIR.'/role_65_payment_reconciler.json'),
        ), true);

        $this->assertTrue($fixture['provenance']['synthetic'], 'role 65 fixture: synthetic provenance');
        $this->assertFalse($fixture['provenance']['student_dialogues_copied'], 'role 65 fixture: no copied dialogues');
        $this->assertSame('implemented', $fixture['implementation_status'], 'role 65: implementation landed');

        $service = new W1PaymentReconcileService;

        foreach ($fixture['cases'] as $case) {
            if (isset($case['input']['scenario'])) {
                $result = $service->reconcile('h6188:golden-direct:'.$case['id'], (array) $case['input']['scenario']);
            } else {
                $result = $service->handle(
                    'h6188:golden-direct:'.$case['id'],
                    (string) $case['input']['message'],
                    (array) ($case['input']['context'] ?? []),
                );
            }

            $expected = $case['expected'];

            $this->assertSame($expected['outcome'], $result->outcome, "{$case['id']}: outcome must match the versioned expected");
            $this->assertSame((bool) $expected['route_to_human'], $result->routeToHuman, "{$case['id']}: route_to_human");

            if (array_key_exists('proposal_requires_human_approval', $expected)) {
                $this->assertSame(
                    (bool) $expected['proposal_requires_human_approval'],
                    $result->proposalRequiresHumanApproval(),
                    "{$case['id']}: proposal_requires_human_approval",
                );
            }
            if (array_key_exists('no_auto_refund', $expected)) {
                $this->assertSame((bool) $expected['no_auto_refund'], $result->noAutoRefund(), "{$case['id']}: no_auto_refund");
            }
            if (array_key_exists('forbidden_contour', $expected)) {
                $this->assertSame($expected['forbidden_contour'], $result->forbiddenContour, "{$case['id']}: forbidden_contour");
            }

            $this->assertContains($result->outcome, W1PaymentReconcileResult::OUTCOMES, "{$case['id']}: canonical outcome");

            // Инварианты money-класса на каждом кейсе.
            $this->assertTrue($result->noAutoRefund(), "{$case['id']}: ноль автоматических возвратов");
            $this->assertTrue($result->proposal->readOnly, "{$case['id']}: предложение строго read-only");
            $this->assertSame([], $result->proposal->actions, "{$case['id']}: предложение не несёт действий");
        }
    }
}
