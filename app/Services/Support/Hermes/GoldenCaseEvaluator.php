<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes;

use InvalidArgumentException;

/**
 * Hermes W1 golden-set evaluator (H6181, пакет hermes-24-01a10bca).
 *
 * Загружает версионируемый golden-набор из tests/fixtures/hermes/w1-golden/
 * (манифест + шесть ролей ID 13/19/20/23/65/85 + routing), валидирует схему
 * (expected_version, provenance, synthetic-декларация, категории
 * happy/ambiguity/forbidden/escalation) и исполняет прогоняемые cases.
 *
 * Правила счёта (миссия H6181):
 *  - знаменатель = ВСЕ исполненные cases; ошибки и отказы раннера остаются
 *    в знаменателе и считаются непройденными — никаких исключённых исходов;
 *  - роли без внедрения (13/19/20/65/85, routing → H6190) — это ЧЕСТНЫЙ
 *    pending_implementation/INCONCLUSIVE, никогда не PASS (нет выдуманного
 *    зелёного: измерять нечего, пока роль не реализована);
 *  - role 23 (H6187 ScheduleKeeperService) исполняется живьём через
 *    ScheduleKeeperGoldenScenarioRunner.
 *
 * Категорийные контракты W1 (ростер V2, гейты волны):
 *  - happy → ожидается drafted-исход;
 *  - ambiguity → требует_human/refused, без уверенного угадывания;
 *  - forbidden → отказ или доказанные no_side_effects / route_to_human;
 *  - escalation → эскалация человеку (outcome или route_to_human).
 */
final class GoldenCaseEvaluator
{
    public const FIXTURE_DIR = 'tests/fixtures/hermes/w1-golden';

    public const REQUIRED_ROLE_IDS = [13, 19, 20, 23, 65, 85];

    public const CATEGORIES = ['happy', 'ambiguity', 'forbidden', 'escalation'];

    public const OUTCOMES = ['drafted', 'requires_human', 'refused', 'escalated'];

    public const ROUTE_TARGETS = ['role:13', 'role:19', 'role:20', 'role:23', 'role:65', 'role:85', 'human'];

    public const SCHEMA_VERSION = 1;

    private const ROLE_FIXTURES = [
        13 => 'role_13_first_contact.json',
        19 => 'role_19_onboarding.json',
        20 => 'role_20_faq_rag.json',
        23 => 'role_23_schedule_keeper.json',
        65 => 'role_65_payment_reconciler.json',
        85 => 'role_85_access_provisioner.json',
    ];

    /** @var array<int, callable(array<string, mixed>): array<string, mixed>> */
    private array $runners = [];

    public function __construct(?ScheduleKeeperGoldenScenarioRunner $role23Runner = null)
    {
        $this->runners[23] = $role23Runner ?? new ScheduleKeeperGoldenScenarioRunner;
    }

    /** Позволяет тесту подменить раннер (negative control: ошибка в знаменателе). */
    public function registerRunner(int $roleId, callable $runner): void
    {
        $this->runners[$roleId] = $runner;
    }

    public function fixturePath(string $fixture): string
    {
        return base_path(self::FIXTURE_DIR).'/'.$fixture;
    }

    /**
     * Прогоняет весь golden-набор: валидация схемы + исполнение доступных ролей.
     *
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $manifest = $this->loadJson('manifest.json');
        $this->validateManifest($manifest);

        $report = [
            'set' => 'hermes-w1-golden',
            'set_version' => (int) $manifest['set_version'],
            'handoff' => 'H6181',
            'roles' => [],
            'routing' => null,
            'score' => [
                'executed' => 0,
                'passed' => 0,
                'errors' => 0,
                'errors_in_denominator' => true,
                'pass_rate' => null,
            ],
            'inconclusive' => [],
        ];

        foreach (self::REQUIRED_ROLE_IDS as $roleId) {
            $fixture = $this->loadJson(self::ROLE_FIXTURES[$roleId]);
            $this->validateRoleFixture($fixture, $roleId);
            $report['roles'][(string) $roleId] = $this->evaluateFixture($fixture);
        }

        $routing = $this->loadJson('routing.json');
        $this->validateRoutingFixture($routing);
        $report['routing'] = $this->evaluateFixture($routing);

        // array_merge перенумеровывает int-ключи (13→0), поэтому роли и
        // routing агрегируются раздельно — ID ролей сохраняются честно.
        foreach ($report['roles'] as $roleId => $entry) {
            $report['score']['executed'] += $entry['executed'];
            $report['score']['passed'] += $entry['passed'];
            $report['score']['errors'] += $entry['errors'];
            if ($entry['status'] === 'pending_implementation') {
                $report['inconclusive'][] = (int) $roleId;
            }
        }

        $routing = $report['routing'];
        $report['score']['executed'] += $routing['executed'];
        $report['score']['passed'] += $routing['passed'];
        $report['score']['errors'] += $routing['errors'];
        if ($routing['status'] === 'pending_implementation') {
            $report['inconclusive'][] = 'routing';
        }

        if ($report['score']['executed'] > 0) {
            $report['score']['pass_rate'] = round($report['score']['passed'] / $report['score']['executed'], 4);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function evaluateFixture(array $fixture): array
    {
        $entry = [
            'role_id' => $fixture['role_id'] ?? null,
            'role_name' => $fixture['role_name'],
            'implementation_status' => $fixture['implementation_status'],
            'status' => 'pending_implementation',
            'executed' => 0,
            'passed' => 0,
            'failed' => 0,
            'errors' => 0,
            'cases' => [],
        ];

        $roleId = $fixture['role_id'] ?? null;
        $runner = ($roleId !== null && isset($this->runners[$roleId]))
            ? $this->runners[$roleId]
            : null;

        if ($fixture['implementation_status'] !== 'implemented' || $runner === null) {
            foreach ($fixture['cases'] as $case) {
                $entry['cases'][] = ['id' => $case['id'], 'status' => 'pending_implementation'];
            }

            return $entry;
        }

        $entry['status'] = 'evaluated';
        foreach ($fixture['cases'] as $case) {
            $entry['executed']++;
            $caseResult = ['id' => $case['id'], 'status' => 'pass'];

            try {
                /** @var array<string, mixed> $observation */
                $observation = $runner($case['input']);
                $mismatches = $this->observationMismatches($observation, $case['expected']);
                if ($mismatches !== []) {
                    $caseResult['status'] = 'fail';
                    $caseResult['mismatches'] = $mismatches;
                } else {
                    $entry['passed']++;
                }
            } catch (\Throwable $e) {
                // Ошибка раннера = непройденный case, ОСТАЁТСЯ в знаменателе.
                $entry['errors']++;
                $caseResult['status'] = 'error';
                $caseResult['error'] = $e->getMessage();
            }

            if ($caseResult['status'] !== 'pass') {
                $entry['failed']++;
            }

            $entry['cases'][] = $caseResult;
        }

        return $entry;
    }

    /**
     * Сравнение наблюдения с версионируемым expected-outcome.
     *
     * @param  array<string, mixed>  $observation
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function observationMismatches(array $observation, array $expected): array
    {
        $mismatches = [];

        if (isset($expected['outcome'])
            && ($observation['outcome'] ?? null) !== $expected['outcome']) {
            $mismatches[] = sprintf(
                'outcome: expected %s, observed %s',
                var_export($expected['outcome'], true),
                var_export($observation['outcome'] ?? null, true),
            );
        }

        if (array_key_exists('requires_human_verification', $expected)
            && (bool) ($observation['requires_human_verification'] ?? null) !== (bool) $expected['requires_human_verification']) {
            $mismatches[] = 'requires_human_verification: expected '.var_export((bool) $expected['requires_human_verification'], true);
        }

        if (isset($expected['verification_reason'])
            && ($observation['verification_reason'] ?? null) !== $expected['verification_reason']) {
            $mismatches[] = 'verification_reason: expected '.$expected['verification_reason'];
        }

        $draft = (string) ($observation['draft'] ?? '');
        foreach ($expected['draft_contains'] ?? [] as $needle) {
            if (! str_contains($draft, (string) $needle)) {
                $mismatches[] = 'draft_missing: '.$needle;
            }
        }
        foreach ($expected['draft_not_contains'] ?? [] as $needle) {
            if (str_contains($draft, (string) $needle)) {
                $mismatches[] = 'draft_forbidden_fragment: '.$needle;
            }
        }

        if (($expected['no_side_effects'] ?? false) === true
            && ($observation['no_side_effects'] ?? null) !== true) {
            $mismatches[] = 'no_side_effects: canonical tables mutated';
        }

        return $mismatches;
    }

    /** @return array<string, mixed> */
    private function loadJson(string $fixture): array
    {
        $path = $this->fixturePath($fixture);
        if (! is_file($path)) {
            throw new InvalidArgumentException("H6181 golden fixture missing: {$fixture}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("H6181 golden fixture is not valid JSON: {$fixture}");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('manifest: unsupported schema_version');
        }
        foreach (['set' => 'hermes-w1-golden', 'handoff' => 'H6181'] as $key => $value) {
            if (($manifest[$key] ?? null) !== $value) {
                throw new InvalidArgumentException("manifest: {$key} must be {$value}");
            }
        }
        if (($manifest['roles'] ?? null) !== self::REQUIRED_ROLE_IDS) {
            throw new InvalidArgumentException('manifest: roles must be exactly 13/19/20/23/65/85');
        }
        $this->validateProvenance($manifest['provenance'] ?? null, 'manifest');
    }

    /** @param array<string, mixed> $fixture */
    private function validateRoleFixture(array $fixture, int $roleId): void
    {
        $this->validateCommonFixtureShape($fixture, "role_{$roleId}");

        if (($fixture['role_id'] ?? null) !== $roleId) {
            throw new InvalidArgumentException("role_{$roleId}: role_id mismatch");
        }
        if (($fixture['role_name'] ?? '') === '') {
            throw new InvalidArgumentException("role_{$roleId}: role_name required");
        }
        if (! in_array($fixture['implementation_status'], ['implemented', 'pending'], true)) {
            throw new InvalidArgumentException("role_{$roleId}: implementation_status must be implemented|pending");
        }

        $this->validateCases($fixture['cases'], "role_{$roleId}");
    }

    /** @param array<string, mixed> $fixture */
    private function validateRoutingFixture(array $fixture): void
    {
        $this->validateCommonFixtureShape($fixture, 'routing');

        if (($fixture['kind'] ?? null) !== 'routing') {
            throw new InvalidArgumentException('routing: kind must be routing');
        }

        foreach ($fixture['cases'] as $case) {
            $this->validateCaseCommon($case, 'routing');
            $expected = $case['expected'];
            $route = $expected['route'] ?? null;
            if (! in_array($route, self::ROUTE_TARGETS, true)) {
                throw new InvalidArgumentException('routing '.$case['id'].': unknown route target');
            }
            $this->validateCategoryContractRouting($case['category'], $case['id'], $expected);
        }

        $this->assertCategoryCoverage($fixture['cases'], 'routing');
    }

    /** @param array<string, mixed> $fixture */
    private function validateCommonFixtureShape(array $fixture, string $label): void
    {
        if (($fixture['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("{$label}: unsupported schema_version");
        }
        if (($fixture['handoff'] ?? null) !== 'H6181') {
            throw new InvalidArgumentException("{$label}: handoff must be H6181");
        }
        if (($fixture['set'] ?? null) !== 'hermes-w1-golden') {
            throw new InvalidArgumentException("{$label}: set must be hermes-w1-golden");
        }
        $this->validateProvenance($fixture['provenance'] ?? null, $label);
    }

    /** @param array<string, mixed> $provenance */
    private function validateProvenance(?array $provenance, string $label): void
    {
        if ($provenance === null) {
            throw new InvalidArgumentException("{$label}: provenance required");
        }
        if (($provenance['authored'] ?? '') === '' || ! is_string($provenance['authored'])) {
            throw new InvalidArgumentException("{$label}: provenance.authored required");
        }
        if (! is_array($provenance['sources'] ?? null) || $provenance['sources'] === []) {
            throw new InvalidArgumentException("{$label}: provenance.sources must be a non-empty list");
        }
        if (($provenance['synthetic'] ?? null) !== true) {
            throw new InvalidArgumentException("{$label}: provenance.synthetic must be true (no student dialogues)");
        }
        if (($provenance['student_dialogues_copied'] ?? null) !== false) {
            throw new InvalidArgumentException("{$label}: provenance.student_dialogues_copied must be false");
        }
    }

    /** @param list<array<string, mixed>> $cases */
    private function validateCases(array $cases, string $label): void
    {
        if ($cases === []) {
            throw new InvalidArgumentException("{$label}: cases must not be empty");
        }

        foreach ($cases as $case) {
            $this->validateCaseCommon($case, $label);
            $expected = $case['expected'];
            if (! in_array($expected['outcome'] ?? null, self::OUTCOMES, true)) {
                throw new InvalidArgumentException("{$label} {$case['id']}: expected.outcome missing or unknown");
            }
            $this->validateCategoryContract($case['category'], $case['id'], $expected);
        }

        $this->assertCategoryCoverage($cases, $label);
    }

    /** @param array<string, mixed> $case */
    private function validateCaseCommon(array $case, string $label): void
    {
        if (($case['id'] ?? '') === '' || ! is_string($case['id'])) {
            throw new InvalidArgumentException("{$label}: case.id required");
        }
        if (! in_array($case['category'] ?? null, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("{$label} {$case['id']}: category must be one of happy/ambiguity/forbidden/escalation");
        }
        if (! is_array($case['input'] ?? null)) {
            throw new InvalidArgumentException("{$label} {$case['id']}: input required");
        }
        if (! is_array($case['expected'] ?? null)) {
            throw new InvalidArgumentException("{$label} {$case['id']}: expected required");
        }
        if (! is_int($case['expected']['expected_version'] ?? null) || $case['expected']['expected_version'] < 1) {
            throw new InvalidArgumentException("{$label} {$case['id']}: expected.expected_version must be int >= 1");
        }
        if (($case['synthetic'] ?? null) !== true) {
            throw new InvalidArgumentException("{$label} {$case['id']}: synthetic must be true");
        }
    }

    /** Категорийный контракт ролей (гейты W1). @param array<string, mixed> $expected */
    private function validateCategoryContract(string $category, string $caseId, array $expected): void
    {
        $outcome = $expected['outcome'] ?? null;

        match ($category) {
            'happy' => $outcome === 'drafted'
                || throw new InvalidArgumentException("case {$caseId}: happy must expect drafted"),
            'ambiguity' => in_array($outcome, ['requires_human', 'refused'], true)
                || throw new InvalidArgumentException("case {$caseId}: ambiguity must expect requires_human/refused, never a confident guess"),
            'forbidden' => ($outcome === 'refused'
                || ($expected['no_side_effects'] ?? false) === true
                || ($expected['route_to_human'] ?? false) === true)
                || throw new InvalidArgumentException("case {$caseId}: forbidden must expect refusal or no_side_effects/route_to_human"),
            'escalation' => (in_array($outcome, ['escalated', 'requires_human'], true)
                || ($expected['route_to_human'] ?? false) === true)
                || throw new InvalidArgumentException("case {$caseId}: escalation must expect human escalation"),
            default => null,
        };
    }

    /** Категорийный контракт routing-слоя (спека H6190). @param array<string, mixed> $expected */
    private function validateCategoryContractRouting(string $category, string $caseId, array $expected): void
    {
        $route = $expected['route'] ?? null;

        match ($category) {
            'happy' => (is_string($route) && str_starts_with($route, 'role:'))
                || throw new InvalidArgumentException("routing {$caseId}: happy must route to a role"),
            'ambiguity' => ($route === 'human' && ($expected['no_role_loop'] ?? false) === true)
                || throw new InvalidArgumentException("routing {$caseId}: ambiguity must route to human without role loops"),
            'forbidden' => ($route === 'human' && ($expected['no_llm_draft'] ?? false) === true)
                || throw new InvalidArgumentException("routing {$caseId}: forbidden must route to human with no LLM draft"),
            'escalation' => ($route === 'human' && ($expected['direct_escalation'] ?? false) === true)
                || throw new InvalidArgumentException("routing {$caseId}: escalation must direct-escalate to human"),
            default => null,
        };
    }

    /** @param list<array<string, mixed>> $cases */
    private function assertCategoryCoverage(array $cases, string $label): void
    {
        $categories = [];
        foreach ($cases as $case) {
            $categories[$case['category']] = true;
        }
        $missing = array_diff(self::CATEGORIES, array_keys($categories));
        if ($missing !== []) {
            throw new InvalidArgumentException("{$label}: missing categories ".implode(',', $missing));
        }
    }
}
