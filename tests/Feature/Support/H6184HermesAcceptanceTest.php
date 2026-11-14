<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Hermes\FirstContact\W1FirstContactResult;
use App\Services\Support\Hermes\FirstContact\W1FirstContactService;
use App\Services\Support\Hermes\GoldenCaseEvaluator;
use App\Services\Support\Hermes\Routing\W1FirstContactRouter;
use App\Services\Support\Hermes\Routing\W1RoutingDecision;
use App\Services\Support\SupportStudentPiiMasker;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * H6184 acceptance (Epic E026, Hermes W1): first-contact роль 13 —
 * первый ответ и эскалация. Обёртка над GoldenCaseEvaluator H6181 +
 * новые тесты first-contact сервиса: mock LLM draft (PII-masked per H6182),
 * escalation per H6133, quota-aware (H6174 default OFF — log-only),
 * no production wiring, no sends (human-send per H6183), no side-effects.
 * Fixtures синтетические (provenance H6181); прод-проб нет.
 */
class H6184HermesAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.hermes_schedule_keeper' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Обёртка над GoldenCaseEvaluator: роль 13 исполняется живьём,
     * роль 23 зелёная, ошибки в знаменателе.
     */
    public function test_wrapper_existing_golden_validator_stays_green(): void
    {
        $service = new W1FirstContactService;
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);

        // Регистрируем раннер роли 13 — вызывает first-contact сервис.
        $evaluator->registerRunner(13, function (array $input) use ($service): array {
            $result = $service->handle(
                'h6184:golden:role13:'.md5(json_encode($input)),
                (string) $input['message'],
                (array) ($input['context'] ?? []),
            );

            return $result->toObservation();
        });

        $report = $evaluator->evaluate();

        $this->assertSame('hermes-w1-golden', $report['set']);
        $this->assertSame(1, $report['set_version']);

        // Роль 13: 4 golden-кейса, все исполнены и пройдены.
        $this->assertSame(4, $report['roles']['13']['executed'], 'role 13: 4 golden cases executed');
        $this->assertSame(4, $report['roles']['13']['passed'], 'role 13: all cases pass');
        $this->assertSame(0, $report['roles']['13']['errors'], 'role 13: no errors');
        $this->assertSame('evaluated', $report['roles']['13']['status'], 'role 13: implemented and evaluated');

        // Роль 23 остаётся зелёной (не сломана).
        $this->assertSame(4, $report['roles']['23']['executed']);
        $this->assertSame(4, $report['roles']['23']['passed']);
        $this->assertSame(0, $report['roles']['23']['errors']);

        // Правило «ошибки в знаменателе» живо.
        $this->assertTrue($report['score']['errors_in_denominator']);
    }

    /** Golden-фикстура: fc-001..fc-004 → ровно тот outcome, что в expected. */
    public function test_golden_fixture_cases_match_expected_outcomes(): void
    {
        $fixture = json_decode((string) file_get_contents(
            base_path(GoldenCaseEvaluator::FIXTURE_DIR.'/role_13_first_contact.json'),
        ), true);

        $this->assertTrue($fixture['provenance']['synthetic'], 'role 13 fixture: synthetic provenance');
        $this->assertFalse($fixture['provenance']['student_dialogues_copied'], 'role 13 fixture: no copied dialogues');
        $this->assertSame('implemented', $fixture['implementation_status'], 'role 13: implementation landed');

        $service = new W1FirstContactService;

        foreach ($fixture['cases'] as $case) {
            $result = $service->handle(
                'h6184:golden-direct:'.$case['id'],
                (string) $case['input']['message'],
                (array) ($case['input']['context'] ?? []),
            );

            $this->assertSame(
                $case['expected']['outcome'],
                $result->outcome,
                "{$case['id']}: outcome must match the versioned expected (".json_encode($case['expected'], JSON_UNESCAPED_UNICODE).')',
            );

            // Однопроходность и single_pass флаг из маршрутизации.
            $this->assertTrue(
                in_array($result->outcome, W1FirstContactResult::OUTCOMES, true),
                "{$case['id']}: outcome must be a canonical value",
            );
        }
    }

    /** Черновик несёт source + confidence + human_send_required (H6183). */
    public function test_first_contact_draft_has_source_confidence_and_human_send(): void
    {
        $service = new W1FirstContactService;
        $result = $service->handle(
            'h6184:test:draft-meta',
            'Здравствуйте! Я записался на курс. Когда начнётся обучение?',
            ['channel' => 'tg', 'author' => 'student'],
        );

        $this->assertSame(W1FirstContactResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertNotNull($result->draft, 'draft must be composed');
        $this->assertNotSame('', $result->draft);
        $this->assertNotNull($result->source, 'draft must carry a source');
        $this->assertStringContainsString('first-contact', $result->source);
        $this->assertNotNull($result->confidence, 'draft must carry a confidence level');
        $this->assertGreaterThan(0.0, $result->confidence);
        $this->assertLessThanOrEqual(1.0, $result->confidence);
        $this->assertTrue($result->humanSendRequired, 'first 30 days: human-send per H6183');
    }

    /** PII (H6182): имена/e-mail/телефоны не утекают в черновик. */
    public function test_pii_masked_before_draft_composition(): void
    {
        $service = new W1FirstContactService;
        $result = $service->handle(
            'h6184:test:pii-mask',
            'Меня зовут Анна Петрова, мой email anna.petrova@example.com, телефон +7 (916) 123-45-67. Хочу записаться на курс.',
            ['channel' => 'tg', 'author' => 'student'],
        );

        $this->assertSame(W1FirstContactResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertGreaterThan(0, $result->piiMaskCount, 'PII substitutions must be counted');

        // Сырое PII не должно попасть в черновик (draft — из замаскированного входа).
        $draft = (string) $result->draft;
        $this->assertStringNotContainsString('Анна Петрова', $draft, 'name must not leak into draft');
        $this->assertStringNotContainsString('anna.petrova@example.com', $draft, 'email must not leak into draft');
        $this->assertStringNotContainsString('+7 (916) 123-45-67', $draft, 'phone must not leak into draft');
    }

    /** PII fail-closed (H6182): identity-net down → текст исключён. */
    public function test_pii_fail_closed_excludes_text_from_prompt(): void
    {
        // Маскер, у которого maskOrFail бросает — симуляция недоступности
        // identity-сета (H6182 fail-closed: «чистоту нельзя доказать → вызова нет»).
        $masker = new class extends SupportStudentPiiMasker
        {
            public function maskOrFail(string $text): array
            {
                throw new \RuntimeException('identity-net unavailable (simulated)');
            }
        };

        $service = new W1FirstContactService(piiMasker: $masker);
        $result = $service->handle(
            'h6184:test:pii-failclosed',
            'Меня зовут Иван, помогите записаться на курс.',
            ['channel' => 'tg'],
        );

        // Fail-closed: текст исключён, черновик строится из шаблона.
        $this->assertSame(W1FirstContactResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertSame(0, $result->piiMaskCount, 'fail-closed: zero substitutions');
        $this->assertNotNull($result->draft, 'draft still composed from template');
    }

    /** Escalation per H6133: деньги/конфликт/чувствительное/тех-сбой → human. */
    public function test_escalation_triggers_per_runbook_h6133(): void
    {
        $service = new W1FirstContactService;

        // Деньги → escalation.
        $result = $service->handle('h6184:test:esc-money', 'Оплата не прошла, что делать?', ['channel' => 'tg']);
        $this->assertSame(W1FirstContactResult::OUTCOME_ESCALATED, $result->outcome);
        $this->assertNull($result->draft, 'escalation: no draft');
        $this->assertSame('human', $result->route);

        // Конфликт → escalation.
        $result = $service->handle('h6184:test:esc-conflict', 'Учитель грубит мне на занятиях', ['channel' => 'tg']);
        $this->assertSame(W1FirstContactResult::OUTCOME_ESCALATED, $result->outcome);
        $this->assertNull($result->draft);

        // Чувствительное → escalation (минуя роли).
        $result = $service->handle('h6184:test:esc-sensitive', 'Всё очень плохо, разберитесь', ['channel' => 'tg', 'sensitive' => true]);
        $this->assertSame(W1FirstContactResult::OUTCOME_ESCALATED, $result->outcome);
        $this->assertSame(W1RoutingDecision::CATEGORY_ESCALATION, $result->routingCategory);

        // Тех-сбой → escalation.
        $result = $service->handle('h6184:test:esc-tech', 'Сайт не открывается уже второй день', ['channel' => 'tg']);
        $this->assertSame(W1FirstContactResult::OUTCOME_ESCALATED, $result->outcome);
    }

    /** Forbidden (fc-003): возврат/скидка → refused, без LLM-черновика. */
    public function test_forbidden_contour_refused_without_draft(): void
    {
        $service = new W1FirstContactService;

        $result = $service->handle(
            'h6184:test:forbidden',
            'Оформите мне возврат за прошлый курс и скидку 50% на новый, я давно у вас учусь.',
            ['channel' => 'tg'],
        );

        $this->assertSame(W1FirstContactResult::OUTCOME_REFUSED, $result->outcome);
        $this->assertNull($result->draft, 'forbidden: no LLM draft');
        $this->assertSame('human', $result->route);
        $this->assertSame(W1RoutingDecision::CATEGORY_FORBIDDEN, $result->routingCategory);
    }

    /**
     * Ambiguity: без уверенного угадывания → requires_human, без черновика.
     */
    public function test_ambiguity_requires_human_without_guess(): void
    {
        $service = new W1FirstContactService;

        $result = $service->handle('h6184:test:ambiguity', 'Не работает', ['channel' => 'tg', 'author' => 'student']);

        $this->assertSame(W1FirstContactResult::OUTCOME_REQUIRES_HUMAN, $result->outcome);
        $this->assertNull($result->draft, 'ambiguity: no confident guess, no draft');
        $this->assertSame('human', $result->route);
    }

    /** Quota OFF (default): log-only, draft composed без enforcement. */
    public function test_quota_machine_default_off_log_only(): void
    {
        // Дефолт: машина выключена (env SCHOOL_ROLE_QUOTA_ENABLED не задан).
        $this->assertFalse(config('school_role_quota.enabled', false), 'quota machine must be OFF by default');

        $service = new W1FirstContactService;
        $result = $service->handle(
            'h6184:test:quota-off',
            'Хочу записаться на курс новичка',
            ['channel' => 'tg'],
        );

        $this->assertSame(W1FirstContactResult::OUTCOME_DRAFTED, $result->outcome, 'quota OFF: draft is still composed');
        $this->assertNotNull($result->draft);
        $this->assertIsArray($result->quotaInfo, 'quota info must be present (log-only)');
        $this->assertArrayHasKey('enabled', $result->quotaInfo);
        $this->assertFalse($result->quotaInfo['enabled'], 'quota info reflects disabled state');
    }

    /** Quota ON + exhausted: requires_human (fallback=none). */
    public function test_quota_machine_denied_when_enabled_and_exhausted(): void
    {
        config([
            'school_role_quota.enabled' => true,
            'school_role_quota.allowances' => ['first-contact-247' => 50],
        ]);

        $service = new W1FirstContactService;

        // Первый запрос: 100 токенов > 50 allowance → hard_stop (0% headroom).
        $result = $service->handle(
            'h6184:test:quota-denied',
            'Хочу записаться на курс новичка',
            ['channel' => 'tg'],
        );

        $this->assertSame(
            W1FirstContactResult::OUTCOME_REQUIRES_HUMAN,
            $result->outcome,
            'quota exhausted: queued for human (fallback=none)',
        );
        $this->assertNull($result->draft, 'quota denied: zero LLM calls');
        $this->assertIsArray($result->quotaInfo);
    }

    /** Нет side-effects: таблицы до/после идентичны (no sends, no writes). */
    public function test_no_side_effects_and_no_outgoing_sends(): void
    {
        $before = $this->tableCounts();

        $service = new W1FirstContactService;
        $inputs = [
            ['h6184:nse:1', 'Здравствуйте! Я записался на курс. Когда начнётся обучение?', []],
            ['h6184:nse:2', 'Оплата не прошла, что делать?', []],
            ['h6184:nse:3', 'Не работает', []],
            ['h6184:nse:4', 'Учитель грубит мне на занятиях', ['sensitive' => true]],
            ['h6184:nse:5', 'Оформите мне возврат за прошлый курс', []],
            ['h6184:nse:6', 'Хочу записаться на курс новичка', []],
            ['h6184:nse:7', 'Как привязать Telegram к кабинету?', []],
            ['h6184:nse:8', 'Какие учебники нужны для занятий?', []],
        ];

        foreach ($inputs as [$key, $message, $context]) {
            $service->handle($key, $message, $context);
        }

        $this->assertSame($before, $this->tableCounts(), 'first-contact service must not touch any table (no side-effects)');
    }

    /** Только канонические маршруты ростера V2, никакой седьмой роли. */
    public function test_only_canonical_route_targets_no_new_role_ids(): void
    {
        $router = new W1FirstContactRouter;
        $service = new W1FirstContactService;
        $routes = [];

        $messages = [
            'Хочу записаться на курс новичка',
            'Оплата не прошла',
            'Не работает',
            'Учитель грубит',
            'Как привязать Telegram к кабинету?',
            'Какие учебники нужны?',
            'Когда у моей группы занятие?',
        ];

        foreach ($messages as $i => $message) {
            $result = $service->handle('h6184:routes:'.$i, $message);
            $routes[] = $result->route;
        }

        foreach ($routes as $route) {
            $this->assertContains($route, GoldenCaseEvaluator::ROUTE_TARGETS, 'route must be a canonical V2 target');
        }

        $roleTargets = array_values(array_unique(array_filter($routes, static fn (string $r): bool => $r !== 'human')));
        foreach ($roleTargets as $target) {
            $this->assertContains(
                (int) substr($target, 5),
                GoldenCaseEvaluator::REQUIRED_ROLE_IDS,
                'no seventh canonical role',
            );
        }
    }

    /** Повторные события → одно назначение (H6190 in-memory ledger). */
    public function test_repeated_events_create_no_duplicate_assignments(): void
    {
        $service = new W1FirstContactService;

        $first = $service->handle('h6184:dup:1', 'Хочу записаться на курс новичка');
        $second = $service->handle('h6184:dup:1', 'Хочу записаться на курс новичка');

        $this->assertSame($first->outcome, $second->outcome, 'same event: same outcome (no duplicate)');
        $this->assertSame($first->route, $second->route);
    }

    /** toObservation() → GoldenCaseEvaluator контракт (outcome/draft/flags). */
    public function test_observation_contract_matches_golden_evaluator(): void
    {
        $service = new W1FirstContactService;
        $result = $service->handle(
            'h6184:test:obs',
            'Здравствуйте! Я записался на курс.',
            ['channel' => 'tg'],
        );

        $observation = $result->toObservation();

        $this->assertArrayHasKey('outcome', $observation);
        $this->assertArrayHasKey('draft', $observation);
        $this->assertArrayHasKey('requires_human_verification', $observation);
        $this->assertArrayHasKey('no_side_effects', $observation);
        $this->assertTrue($observation['no_side_effects'], 'observation declares no side-effects');
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
}
