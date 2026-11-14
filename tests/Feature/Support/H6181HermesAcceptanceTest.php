<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Hermes\GoldenCaseEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6181 acceptance (пакет hermes-24-01a10bca): golden-набор первой волны —
 * шесть ролей (13/19/20/23/65/85) и маршрутизация (happy/ambiguity/
 * forbidden/escalation). Проверяет версионируемые expected-outcomes и
 * provenance, runnable evaluator, честный INCONCLUSIVE для ролей без
 * внедрения и правило «ошибки/отказы в знаменателе».
 */
class H6181HermesAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Роль 23 (H6187) — единственная внедрённая роль волны: golden cases
        // исполняются против живого ScheduleKeeperService.
        config(['features.hermes_schedule_keeper' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-11 14:35:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_golden_set_loads_and_covers_six_roles_and_routing(): void
    {
        $report = $this->app->make(GoldenCaseEvaluator::class)->evaluate();

        $this->assertSame('hermes-w1-golden', $report['set']);
        $this->assertSame(1, $report['set_version']);
        $this->assertSame('H6181', $report['handoff']);

        foreach (GoldenCaseEvaluator::REQUIRED_ROLE_IDS as $roleId) {
            $this->assertArrayHasKey((string) $roleId, $report['roles'], "role {$roleId} missing from golden set");
            $this->assertSame($roleId, $report['roles'][(string) $roleId]['role_id']);
        }
        $this->assertNotNull($report['routing']);
        $this->assertSame('routing', $report['routing']['role_name']);
    }

    public function test_every_role_and_routing_fixture_covers_all_four_categories(): void
    {
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);

        $fixtures = array_merge(
            array_map(fn (int $id): string => $this->fixtureNameForRole($id), GoldenCaseEvaluator::REQUIRED_ROLE_IDS),
            ['routing.json'],
        );

        foreach ($fixtures as $fixture) {
            $raw = json_decode((string) file_get_contents($evaluator->fixturePath($fixture)), true);
            $categories = array_values(array_unique(array_column($raw['cases'], 'category')));

            sort($categories);
            $expected = GoldenCaseEvaluator::CATEGORIES;
            sort($expected);

            $this->assertSame($expected, $categories, "{$fixture}: must cover exactly the four W1 categories");
        }
    }

    public function test_every_case_is_versioned_and_provenance_is_declared(): void
    {
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);
        $fixtures = array_merge(
            array_map(fn (int $id): string => $this->fixtureNameForRole($id), GoldenCaseEvaluator::REQUIRED_ROLE_IDS),
            ['routing.json'],
        );

        foreach ($fixtures as $fixture) {
            $raw = json_decode((string) file_get_contents($evaluator->fixturePath($fixture)), true);

            $this->assertSame(1, $raw['schema_version'], "{$fixture}: schema_version");
            $this->assertSame('H6181', $raw['handoff']);
            $this->assertTrue($raw['provenance']['synthetic'], "{$fixture}: synthetic declaration");
            $this->assertFalse($raw['provenance']['student_dialogues_copied'], "{$fixture}: no student dialogues");
            $this->assertNotEmpty($raw['provenance']['sources'], "{$fixture}: provenance sources");

            $ids = [];
            foreach ($raw['cases'] as $case) {
                $this->assertSame(true, $case['synthetic'], "{$fixture} {$case['id']}: synthetic case");
                $this->assertIsInt($case['expected']['expected_version']);
                $this->assertGreaterThanOrEqual(1, $case['expected']['expected_version']);
                $ids[] = $case['id'];
            }
            $this->assertSame(count($ids), count(array_unique($ids)), "{$fixture}: case ids must be unique");
        }
    }

    public function test_role_23_golden_cases_pass_against_live_service(): void
    {
        $report = $this->app->make(GoldenCaseEvaluator::class)->evaluate();

        $role23 = $report['roles']['23'];
        $this->assertSame('implemented', $role23['implementation_status']);
        $this->assertSame('evaluated', $role23['status']);
        $this->assertSame(4, $role23['executed']);
        $this->assertSame(4, $role23['passed']);
        $this->assertSame(0, $role23['errors']);
        $this->assertSame(0, $role23['failed']);

        // Общий счёт: только исполненные cases роли 23 в знаменателе.
        $this->assertSame(4, $report['score']['executed']);
        $this->assertSame(4, $report['score']['passed']);
        $this->assertSame(1.0, $report['score']['pass_rate']);
        $this->assertTrue($report['score']['errors_in_denominator']);
    }

    public function test_pending_roles_are_inconclusive_never_pass(): void
    {
        $report = $this->app->make(GoldenCaseEvaluator::class)->evaluate();

        // Роль 13 реализована (H6184, implementation_status=implemented); в этом
        // тесте раннер роли 13 не регистрируется — она вне контекста pending-проверки.
        // Роль 19 реализована (H6186, implementation_status=implemented); раннер
        // роли 19 здесь тоже не регистрируется — вне контекста pending-проверки.
        // Роль 20 реализована (H6185, implementation_status=implemented; раннер
        // регистрируется в H6185HermesAcceptanceTest) — вне контекста pending-проверки.
        // Роль 23 реализована (H6187) и проверяется отдельно.
        // Роль 85 реализована (H6189, implementation_status=implemented; раннер
        // регистрируется в H6189HermesAcceptanceTest) — вне контекста pending-проверки.
        foreach ([65] as $roleId) {
            $entry = $report['roles'][(string) $roleId];
            $this->assertSame('pending', $entry['implementation_status']);
            $this->assertSame('pending_implementation', $entry['status']);
            $this->assertSame(0, $entry['executed'], "role {$roleId}: pending role must not be executed");
            $this->assertSame(0, $entry['passed'], "role {$roleId}: no invented PASS without implementation");
        }

        $this->assertSame('pending_implementation', $report['routing']['status']);
        $this->assertSame(0, $report['routing']['executed']);

        // Честный INCONCLUSIVE: роли без внедрения перечислены явно.
        $inconclusive = $report['inconclusive'];
        foreach ([65] as $roleId) {
            $this->assertContains($roleId, $inconclusive);
        }
        $this->assertContains('routing', $inconclusive);
        // Роль 23 реализована и исполнена — она НЕ inconclusive.
        $this->assertNotContains(23, $inconclusive);
    }

    public function test_runner_errors_stay_in_denominator_as_failures(): void
    {
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);

        // Negative control: раннер всегда падает — ошибки обязаны остаться
        // в знаменателе и обнулить pass_rate, а не исключить cases.
        $evaluator->registerRunner(23, function (): array {
            throw new \RuntimeException('synthetic runner failure');
        });

        $report = $evaluator->evaluate();

        $role23 = $report['roles']['23'];
        $this->assertSame('evaluated', $role23['status']);
        $this->assertSame(4, $role23['executed']);
        $this->assertSame(0, $role23['passed']);
        $this->assertSame(4, $role23['errors']);
        $this->assertSame(0.0, $report['score']['pass_rate']);
        $this->assertSame(4, $report['score']['executed']);
    }

    public function test_fixtures_contain_no_student_pii_or_copied_dialogues(): void
    {
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);
        $fixtures = array_merge(
            array_map(fn (int $id): string => $this->fixtureNameForRole($id), GoldenCaseEvaluator::REQUIRED_ROLE_IDS),
            ['manifest.json', 'routing.json'],
        );

        foreach ($fixtures as $fixture) {
            $rawText = (string) file_get_contents($evaluator->fixturePath($fixture));

            // Никаких адресов, telegram-хэндлов и телефоноподобных цепочек:
            // набор синтетический, ученические переписки не копировались.
            $this->assertDoesNotMatchRegularExpression('/[\w.-]+@[\w.-]+/', $rawText, "{$fixture}: e-mail-like PII");
            $this->assertDoesNotMatchRegularExpression('/@\w{3,}/', $rawText, "{$fixture}: telegram-handle-like PII");
            $this->assertDoesNotMatchRegularExpression('/\d{7,}/', $rawText, "{$fixture}: phone-like digit runs");
        }
    }

    public function test_forbidden_cases_expect_refusal_or_no_side_effects(): void
    {
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);
        $report = $evaluator->evaluate();

        // Контракт категории forbidden уже валидируется evaluator'ом (он бы
        // бросил InvalidArgumentException), но фиксируем и в тесте: каждая
        // роль и routing обязаны иметь forbidden-case с отказом/без мутаций.
        foreach (GoldenCaseEvaluator::REQUIRED_ROLE_IDS as $roleId) {
            $raw = json_decode((string) file_get_contents($evaluator->fixturePath($this->fixtureNameForRole($roleId))), true);
            $forbidden = array_values(array_filter($raw['cases'], fn (array $c): bool => $c['category'] === 'forbidden'));
            $this->assertCount(1, $forbidden, "role {$roleId}: exactly one forbidden case");

            $expected = $forbidden[0]['expected'];
            $this->assertTrue(
                ($expected['outcome'] ?? null) === 'refused'
                || ($expected['no_side_effects'] ?? false) === true
                || ($expected['route_to_human'] ?? false) === true,
                "role {$roleId}: forbidden case must expect refusal or no_side_effects/route_to_human",
            );
        }
    }

    private function fixtureNameForRole(int $roleId): string
    {
        return match ($roleId) {
            13 => 'role_13_first_contact.json',
            19 => 'role_19_onboarding.json',
            20 => 'role_20_faq_rag.json',
            23 => 'role_23_schedule_keeper.json',
            65 => 'role_65_payment_reconciler.json',
            85 => 'role_85_access_provisioner.json',
            default => throw new \InvalidArgumentException("unknown role {$roleId}"),
        };
    }
}
