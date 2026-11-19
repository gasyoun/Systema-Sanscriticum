<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Hermes\GoldenCaseEvaluator;
use App\Services\Support\Hermes\Routing\W1FirstContactRouter;
use App\Services\Support\Hermes\Routing\W1FirstContactRoutingLayer;
use App\Services\Support\Hermes\Routing\W1RoutingDecision;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * H6190 acceptance (пакет hermes-24-01a10bca): общий слой маршрутизации W1 —
 * first-contact без циклов и дублей. Обёртка над существующими
 * тестами/валидаторами owning repo (GoldenCaseEvaluator H6181 + его
 * golden-фикстуры) плюс новые тесты слоя: happy/ambiguity/forbidden/
 * escalation по контракту tests/fixtures/hermes/w1-golden/routing.json,
 * деньги/конфликт → эскалация человеку (runbook H6133), повторные события,
 * петли и неизвестные маршруты без двойных назначений, никаких исходящих
 * сообщений. Фикстуры синтетические (provenance H6181), прод-проб нет.
 */
class H6190HermesAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.hermes_schedule_keeper' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-11 14:35:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Обёртка над существующим валидатором: golden-набор H6181 валиден,
     * роль 23 исполняется живьём, правило «ошибки в знаменателе» живо.
     */
    public function test_wrapper_existing_golden_validator_stays_green(): void
    {
        $report = $this->app->make(GoldenCaseEvaluator::class)->evaluate();

        $this->assertSame('hermes-w1-golden', $report['set']);
        $this->assertSame(4, $report['roles']['23']['executed']);
        $this->assertSame(4, $report['roles']['23']['passed']);
        $this->assertSame(0, $report['roles']['23']['errors']);
        $this->assertTrue($report['score']['errors_in_denominator']);
    }

    /**
     * Контракт routing.json (спека H6190): happy → роль, ambiguity → human
     * без петель, forbidden → human без LLM-черновика, escalation → human
     * прямой эскалацией. Сверка с версионированными expected-outcomes.
     */
    public function test_routing_golden_cases_match_versioned_expected_outcomes(): void
    {
        $fixture = $this->routingFixture();

        $this->assertTrue($fixture['provenance']['synthetic'], 'routing.json: synthetic provenance');
        $this->assertFalse($fixture['provenance']['student_dialogues_copied'], 'routing.json: no copied dialogues');

        $layer = new W1FirstContactRoutingLayer;
        $flagsChecked = 0;

        foreach ($fixture['cases'] as $case) {
            $result = $layer->routeAndAssign(
                'h6190-fixture:'.$case['id'],
                (string) $case['input']['message'],
                $case['input']['context'] ?? [],
            );
            $decision = $result->assignment->decision;

            $this->assertTrue($result->created, "{$case['id']}: exactly one assignment");
            $this->assertSame(
                $case['expected']['route'],
                $decision->route,
                "{$case['id']}: route must match the versioned expected outcome",
            );

            foreach (['single_pass', 'no_role_loop', 'no_llm_draft', 'direct_escalation'] as $flag) {
                if (array_key_exists($flag, $case['expected'])) {
                    $flagsChecked++;
                    $this->assertSame(
                        (bool) $case['expected'][$flag],
                        $decision->flags()[$flag],
                        "{$case['id']}: {$flag}",
                    );
                }
            }

            // Однопроходность: каждое решение фиксирует single_pass.
            $this->assertTrue($decision->singlePass, "{$case['id']}: single pass");
        }

        // Все четыре категорийные метки контракта проверены (rt-001..rt-004).
        $this->assertSame(4, $flagsChecked);
        $this->assertSame(count($fixture['cases']), $layer->ledger()->count(), 'one assignment per event');
    }

    /**
     * Согласованность с роль-фикстурами H6181 (тот же движок, не параллельный
     * механизм): message-кейсы ролей маршрутизируются в свою роль либо к
     * человеку, delegate_hint (fq-002) исполняется слоем напрямую — один
     * проход вместо петли роль 20 → роль 23.
     */
    public function test_role_fixture_message_cases_agree_with_shared_router(): void
    {
        $router = new W1FirstContactRouter;
        $roleFixtures = [
            13 => 'role_13_first_contact.json',
            19 => 'role_19_onboarding.json',
            20 => 'role_20_faq_rag.json',
            65 => 'role_65_payment_reconciler.json',
            85 => 'role_85_access_provisioner.json',
        ];

        foreach ($roleFixtures as $roleId => $fixtureName) {
            $raw = json_decode((string) file_get_contents(
                base_path(GoldenCaseEvaluator::FIXTURE_DIR.'/'.$fixtureName),
            ), true);

            foreach ($raw['cases'] as $case) {
                if (! isset($case['input']['message'])) {
                    continue; // сценарные кейсы (role 23/65/85) — вне текстового роутинга
                }

                $decision = $router->decide(
                    (string) $case['input']['message'],
                    $case['input']['context'] ?? [],
                );
                $expected = $case['expected'];

                if (isset($expected['delegate_hint'])) {
                    $this->assertSame($expected['delegate_hint'], $decision->route, "{$case['id']}: delegate_hint");
                } elseif (($expected['route_to_human'] ?? false) === true) {
                    $this->assertSame('human', $decision->route, "{$case['id']}: route_to_human");
                } else {
                    $this->assertSame('role:'.$roleId, $decision->route, "{$case['id']}: drafted → own role");
                }
            }
        }
    }

    /**
     * Миссия H6190 + runbook H6133: деньги и конфликт эскалируются человеку;
     * мутационные требования (возврат/списание долга) не получают LLM-черновика.
     */
    public function test_money_and_conflict_escalate_to_human_per_runbook(): void
    {
        $router = new W1FirstContactRouter;

        $money = [
            'Оплата не прошла, что делать?',
            'Списалось дважды за прошлый месяц',
            'Когда будет возврат за прошлый курс?',
        ];
        foreach ($money as $message) {
            $decision = $router->decide($message);
            $this->assertSame('human', $decision->route, $message);
            $this->assertTrue($decision->directEscalation, $message.': money escalates per runbook');
            $this->assertSame('runbook_money', $decision->reason, $message);
        }

        // Мутации денежного контура — запрещённый контур: без черновика LLM.
        $mutations = [
            'Спишите мне долг, я больше не смогу платить',
            'Оформите мне возврат за прошлый курс',
        ];
        foreach ($mutations as $message) {
            $decision = $router->decide($message);
            $this->assertSame('human', $decision->route, $message);
            $this->assertTrue($decision->directEscalation, $message.': money escalates');
            $this->assertTrue($decision->noLlmDraft, $message.': money mutation never drafted');
        }

        $conflicts = [
            'Учитель грубит мне на занятиях, я хочу подать жалобу',
            'Я пожалуюсь в Роспотребнадзор, если вы не решите вопрос',
        ];
        foreach ($conflicts as $message) {
            $decision = $router->decide($message);
            $this->assertSame('human', $decision->route, $message);
            $this->assertTrue($decision->directEscalation, $message.': conflict escalates per runbook');
            $this->assertSame('runbook_conflict', $decision->reason, $message);
        }

        // Чувствительное обращение — прямая эскалация, минуя роли.
        $sensitive = $router->decide('Всё очень плохо, разберитесь', ['sensitive' => true]);
        $this->assertSame('human', $sensitive->route);
        $this->assertTrue($sensitive->directEscalation);
    }

    /**
     * Миссия H6190: repeated event, loop и unknown route не создают двойных
     * назначений — ровно одно назначение на ключ события.
     */
    public function test_repeated_events_loops_unknown_routes_create_no_duplicate_assignments(): void
    {
        $layer = new W1FirstContactRoutingLayer;

        // Повторные доставки одного события (ретрай вебхука).
        $first = $layer->routeAndAssign('tg:demo:1001', 'Когда у моей группы ближайшее занятие по расписанию?');
        $second = $layer->routeAndAssign('tg:demo:1001', 'Когда у моей группы ближайшее занятие по расписанию?');
        $third = $layer->routeAndAssign('tg:demo:1001', 'Когда у моей группы ближайшее занятие по расписанию?');

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertTrue($second->duplicateSuppressed);
        $this->assertSame(3, $third->assignment->attemptCount);
        $this->assertSame(1, $layer->ledger()->count(), 'repeated events: one assignment');
        $this->assertSame('role:23', $first->assignment->decision->route);

        // Петля: роль отскочила назад в маршрутизацию с другим вердиктом.
        $loop = $layer->assignDecision('tg:demo:1001', W1RoutingDecision::ambiguity('loop_bounce_from_role'));
        $this->assertTrue($loop->loopSuppressed);
        $this->assertSame(1, $layer->ledger()->count(), 'loops: no second assignment');
        $this->assertSame('role:23', $loop->assignment->decision->route, 'terminal: first decision survives');
        $this->assertSame(1, $loop->assignment->loopSuppressedCount);

        $loopAgain = $layer->assignDecision('tg:demo:1001', W1RoutingDecision::escalation('loop_bounce_again'));
        $this->assertTrue($loopAgain->loopSuppressed);
        $this->assertSame(2, $loopAgain->assignment->loopSuppressedCount);
        $this->assertSame(1, $layer->ledger()->count(), 'loops stay suppressed');

        // Неизвестный маршрут (вне канонического ростера V2) → human-фолбэк,
        // назначение одно, повтор не дублирует.
        $unknown = new W1RoutingDecision('role:17', W1RoutingDecision::CATEGORY_HAPPY, true, false, false, false, 'external_delegate');
        $result = $layer->assignDecision('tg:demo:1002', $unknown);
        $this->assertTrue($result->unknownRouteFallback);
        $this->assertSame('human', $result->assignment->decision->route);
        $this->assertTrue($result->assignment->decision->noLlmDraft, 'unknown route never drafted');
        $this->assertSame(2, $layer->ledger()->count());

        $repeat = $layer->assignDecision('tg:demo:1002', $unknown);
        $this->assertFalse($repeat->created);
        $this->assertTrue($repeat->duplicateSuppressed);
        $this->assertSame(2, $layer->ledger()->count(), 'unknown route: no duplicate assignment');

        // Пустой ключ события — ошибка контракта, не тихий дубль.
        $this->expectException(InvalidArgumentException::class);
        $layer->assignDecision('  ', W1RoutingDecision::ambiguity('x'));
    }

    /**
     * Никаких исходящих сообщений и никаких side-effects: снапшот всех таблиц
     * до/после полного прогона слоя идентичен (паттерн H6181
     * ScheduleKeeperGoldenScenarioRunner::no_side_effects).
     */
    public function test_no_outgoing_messages_and_no_side_effects(): void
    {
        $before = $this->tableCounts();

        $layer = new W1FirstContactRoutingLayer;
        foreach ($this->routingFixture()['cases'] as $case) {
            $layer->routeAndAssign('h6190-nse:'.$case['id'], (string) $case['input']['message'], $case['input']['context'] ?? []);
        }
        foreach ([
            'Оплата не прошла, что делать?',
            'Учитель грубит мне на занятиях, я хочу подать жалобу',
            'Помогите, у меня проблема',
            'Хочу записаться на курс новичка',
        ] as $i => $message) {
            $layer->routeAndAssign('h6190-nse:x'.$i, $message);
        }

        $this->assertSame($before, $this->tableCounts(), 'routing layer must not touch any table');
        $this->assertSame(8, $layer->ledger()->count(), 'assignments live in memory only');
    }

    /**
     * Слой — не новый канонический role ID: только цели ростера V2
     * (GoldenCaseEvaluator::ROUTE_TARGETS), никакой седьмой роли.
     */
    public function test_routing_emits_only_canonical_route_targets(): void
    {
        $router = new W1FirstContactRouter;
        $routes = [];

        foreach ($this->routingFixture()['cases'] as $case) {
            $routes[] = $router->decide((string) $case['input']['message'], $case['input']['context'] ?? [])->route;
        }
        foreach ([
            'Оплата не прошла, что делать?',
            'Сколько стоит курс?', // деньги → human, не роль
            'В какой я группе?',
            'Какая ссылка на вебинар сегодня?',
            'Какие учебники нужны для занятий?',
            'Как привязать Telegram к кабинету?',
            'Хочу записаться на курс новичка',
        ] as $message) {
            $routes[] = $router->decide($message)->route;
        }

        foreach ($routes as $route) {
            $this->assertContains($route, GoldenCaseEvaluator::ROUTE_TARGETS, 'route must be a canonical V2 target');
        }

        $roleTargets = array_values(array_unique(array_filter($routes, static fn (string $r): bool => $r !== 'human')));
        foreach ($roleTargets as $target) {
            $this->assertContains((int) substr($target, 5), GoldenCaseEvaluator::REQUIRED_ROLE_IDS, 'no seventh canonical role');
        }
    }

    /** @return array<string, mixed> */
    private function routingFixture(): array
    {
        return json_decode((string) file_get_contents(
            base_path(GoldenCaseEvaluator::FIXTURE_DIR.'/routing.json'),
        ), true);
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
