<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Concerns;

use App\Models\User;
use App\Services\Support\Hermes\GoldenCaseEvaluator;
use App\Services\Support\Hermes\Onboarding\W1BindingTokenState;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepAction;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepResult;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepService;
use App\Services\Support\Hermes\Routing\W1FirstContactRoutingLayer;
use App\Support\OnboardingChecklist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * H6186 сценарии безопасности и границ (роль 19): эскалация per runbook,
 * запрещённые контуры, ambiguity без угадывания, контракт классификатора
 * токена, квота OFF/ON (H6174), no side-effects + no sends, только канонические
 * маршруты ростера V2, идемпотентность повторных событий, контракт toObservation.
 * Часть H6186HermesAcceptanceTest.
 */
trait H6186BindingSafetyScenarios
{
    /**
     * Эскалация per runbook H6133: тех-сбой привязки, деньги, конфликт и
     * многократный сбой (binding_state=failing_repeatedly) → человеку, без
     * черновика и без токена.
     */
    public function test_escalation_triggers_per_runbook(): void
    {
        $service = new W1BotBindingPrepService;

        // Тех-сбой (ob-004): многократно не приходит код подтверждения.
        $tech = $service->handle(
            'h6186:test:esc-tech',
            'Пятый день не приходит код подтверждения при привязке. Пробовал много раз, ничего не выходит.',
            ['channel' => 'tg', 'binding_state' => 'failing_repeatedly'],
        );
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_ESCALATED, $tech->outcome);
        $this->assertNull($tech->draft, 'escalation: no draft');
        $this->assertNull($tech->action, 'escalation: no token prepared');
        $this->assertSame('human', $tech->route);

        // Деньги → escalation (runbook).
        $money = $service->handle('h6186:test:esc-money', 'Оплата не прошла, что делать?', ['channel' => 'tg']);
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_ESCALATED, $money->outcome);

        // Конфликт → escalation.
        $conflict = $service->handle('h6186:test:esc-conflict', 'Учитель грубит мне на занятиях', ['channel' => 'tg']);
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_ESCALATED, $conflict->outcome);

        // Многократный сбой привязки даже при happy-сообщении → escalation.
        $repeated = $service->handle(
            'h6186:test:esc-repeated',
            'Как привязать мой Telegram к личному кабинету?',
            ['channel' => 'tg', 'binding_state' => 'failing_repeatedly'],
        );
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_ESCALATED, $repeated->outcome, 'checklist does not help anymore — human');
    }

    /** Forbidden: мутационные требования привязки/денег → refused, без черновика. */
    public function test_forbidden_contours_refused_without_draft(): void
    {
        $service = new W1BotBindingPrepService;

        // Контур доступа (ob-003).
        $access = $service->handle(
            'h6186:test:forb-access',
            'Привяжите мой Telegram к кабинету моей жены, чтобы я отвечал за неё на занятиях. Данные кабинета у меня есть.',
            ['channel' => 'tg'],
        );
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REFUSED, $access->outcome);
        $this->assertSame('access', $access->forbiddenContour);
        $this->assertNull($access->draft, 'forbidden: no LLM draft');
        $this->assertNull($access->action);

        // Денежный контур (fc-003-словарь) → refused, контур «money».
        $money = $service->handle(
            'h6186:test:forb-money',
            'Оформите мне возврат за прошлый курс и скидку 50% на новый, я давно у вас учусь.',
            ['channel' => 'tg'],
        );
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REFUSED, $money->outcome);
        $this->assertSame('money', $money->forbiddenContour, 'refund/discount demand: money contour');
        $this->assertNull($money->draft);
    }

    /**
     * Ambiguity и неизвестное состояние: без уверенного угадывания →
     * requires_human (ob-002), включая неизвестное binding_state.
     */
    public function test_ambiguity_requires_human_without_guess(): void
    {
        $service = new W1BotBindingPrepService;

        // ob-002: состояние привязки неизвестно, «проверьте».
        $result = $service->handle(
            'h6186:test:ambiguity',
            'Я не помню, привязан ли у меня Telegram. Проверьте, пожалуйста, и скажите, что делать дальше.',
            ['channel' => 'tg', 'binding_state' => 'unknown'],
        );

        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REQUIRES_HUMAN, $result->outcome);
        $this->assertTrue($result->noConfidentGuess);
        $this->assertNull($result->draft, 'ambiguity: no confident guess, no draft');
        $this->assertNull($result->action, 'ambiguity: no token prepared');
        $this->assertSame('human', $result->route);

        // Happy-сообщение, но состояние привязки неизвестно → тоже к человеку.
        $unknownState = $service->handle(
            'h6186:test:unknown-state',
            'Как привязать мой Telegram к личному кабинету?',
            ['channel' => 'tg', 'binding_state' => 'unknown'],
        );
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REQUIRES_HUMAN, $unknownState->outcome);
        $this->assertTrue($unknownState->noConfidentGuess);

        // Токен предъявлен, но сверить не с чем → без угадывания.
        $unverifiable = $service->handle(
            'h6186:test:unknown-token',
            'Как привязать мой Telegram к личному кабинету?',
            ['channel' => 'tg', 'binding_state' => 'unlinked', 'claimed_link_token' => 'mystery-token-5555'],
        );
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REQUIRES_HUMAN, $unverifiable->outcome);
        $this->assertSame(W1BindingTokenState::UNKNOWN->value, $unverifiable->tokenState);
    }

    /** Контракт чистого классификатора токена (без I/O). */
    public function test_token_state_classifier_contract(): void
    {
        $this->assertSame(W1BindingTokenState::NONE, W1BindingTokenState::classify('', 'live', false));
        $this->assertSame(W1BindingTokenState::ACTIVE, W1BindingTokenState::classify('tok', 'tok', false));
        $this->assertSame(W1BindingTokenState::EXPIRED, W1BindingTokenState::classify('old', 'new', false), 'rotated away = expired');
        $this->assertSame(W1BindingTokenState::UNKNOWN, W1BindingTokenState::classify('tok', null, false), 'nothing to compare = unknown');
        $this->assertSame(W1BindingTokenState::REPLAYED, W1BindingTokenState::classify('tok', 'tok', true), 'presented after binding = replay');
        $this->assertSame(W1BindingTokenState::REPLAYED, W1BindingTokenState::classify('tok', 'other', true));
        $this->assertSame(W1BindingTokenState::NONE, W1BindingTokenState::fromContext([]));
        $this->assertSame(W1BindingTokenState::UNKNOWN, W1BindingTokenState::fromContext(['binding_token_state' => 'garbage']), 'unknown state never guessed');
    }

    /** Quota OFF (default): log-only, черновик собран без enforcement (H6174). */
    public function test_quota_machine_default_off_log_only(): void
    {
        $this->assertFalse(config('school_role_quota.enabled', false), 'quota machine must be OFF by default');

        $service = new W1BotBindingPrepService;
        $result = $service->handle(
            'h6186:test:quota-off',
            'Как привязать мой Telegram к личному кабинету?',
            ['channel' => 'tg', 'binding_state' => 'unlinked'],
        );

        $this->assertSame(W1BotBindingPrepResult::OUTCOME_DRAFTED, $result->outcome, 'quota OFF: draft is still composed');
        $this->assertNotNull($result->draft);
        $this->assertIsArray($result->quotaInfo, 'quota info must be present (log-only)');
        $this->assertArrayHasKey('enabled', $result->quotaInfo);
        $this->assertFalse($result->quotaInfo['enabled'], 'quota info reflects disabled state');
        $this->assertSame('disabled', $result->quotaInfo['draft_verdict']['status'] ?? null, 'reserve() is log-only when disabled');
    }

    /** Quota ON + исчерпана: requires_human (fallback=none), ноль LLM-вызовов. */
    public function test_quota_machine_denied_when_enabled_and_exhausted(): void
    {
        config([
            'school_role_quota.enabled' => true,
            'school_role_quota.allowances' => [W1BotBindingPrepService::ROLE_SLUG => 50],
        ]);

        $service = new W1BotBindingPrepService;

        // 100 токенов композиции > 50 allowance → hard_stop.
        $result = $service->handle(
            'h6186:test:quota-denied',
            'Как привязать мой Telegram к личному кабинету?',
            ['channel' => 'tg', 'binding_state' => 'unlinked'],
        );

        $this->assertSame(
            W1BotBindingPrepResult::OUTCOME_REQUIRES_HUMAN,
            $result->outcome,
            'quota exhausted: queued for human (fallback=none)',
        );
        $this->assertNull($result->draft, 'quota denied: zero LLM calls');
        $this->assertNull($result->action, 'quota denied: no token prepared');
    }

    /**
     * Нет side-effects: таблицы до/после идентичны (никаких записей, никаких
     * исходящих отправок, привязка не применяется) даже при генерации токена.
     */
    public function test_no_side_effects_and_no_outgoing_sends(): void
    {
        Http::fake();

        $user = User::factory()->create(['telegram_auth_token' => 'untouched-token-6666', 'telegram_id' => null]);

        $before = $this->tableCounts();

        $service = new W1BotBindingPrepService;
        $inputs = [
            ['h6186:nse:1', 'Как привязать мой Telegram к личному кабинету?', ['binding_state' => 'unlinked']],
            ['h6186:nse:2', 'Пятый день не приходит код подтверждения при привязке.', ['binding_state' => 'failing_repeatedly']],
            ['h6186:nse:3', 'Привяжите мой Telegram к кабинету моей жены.', ['third_party_account' => true]],
            ['h6186:nse:4', 'Я не помню, привязан ли у меня Telegram.', ['binding_state' => 'unknown']],
            ['h6186:nse:5', 'Как привязать мой Telegram к личному кабинету?', ['binding_state' => 'unlinked', 'binding_token_state' => 'expired', 'claimed_link_token' => 'stale-7777']],
            ['h6186:nse:6', 'Как привязать мой Telegram к личному кабинету?', ['binding_state' => 'unlinked', 'claimed_link_token' => 'old-8888', 'claimed_user_id' => $user->id]],
            ['h6186:nse:7', 'Оплата не прошла, что делать?', []],
            ['h6186:nse:8', 'Как привязать мой Telegram к личному кабинету?', ['binding_state' => 'unlinked', 'binding_token_state' => 'replayed']],
        ];

        foreach ($inputs as [$key, $message, $context]) {
            $service->handle($key, $message, $context);
        }

        $this->assertSame($before, $this->tableCounts(), 'prep service must not touch any table (no side-effects)');
        $this->assertSame('untouched-token-6666', $user->refresh()->telegram_auth_token, 'binding token is data — never applied');
        $this->assertNull($user->telegram_id, 'no binding is ever applied automatically');
        Http::assertNothingSent();
    }

    /** Только канонические маршруты ростера V2, никакой седьмой роли. */
    public function test_only_canonical_route_targets_no_new_role_ids(): void
    {
        $service = new W1BotBindingPrepService;
        $routes = [];

        $messages = [
            'Как привязать мой Telegram к личному кабинету?',
            'Оплата не прошла',
            'Не работает',
            'Учитель грубит',
            'Я не помню, привязан ли у меня Telegram',
            'Какие учебники нужны?',
            'Когда у моей группы занятие?',
        ];

        foreach ($messages as $i => $message) {
            $result = $service->handle('h6186:routes:'.$i, $message);
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

    /** Повторные события → одно назначение (H6190 in-memory ledger, без дублей). */
    public function test_repeated_events_create_no_duplicate_assignments(): void
    {
        $layer = new W1FirstContactRoutingLayer;
        $service = new W1BotBindingPrepService(routingLayer: $layer);

        $first = $service->handle('h6186:dup:1', 'Как привязать мой Telegram к личному кабинету?', ['binding_state' => 'unlinked']);
        $second = $service->handle('h6186:dup:1', 'Как привязать мой Telegram к личному кабинету?', ['binding_state' => 'unlinked']);

        $this->assertSame($first->outcome, $second->outcome, 'same event: same outcome (no duplicate)');
        $this->assertSame($first->route, $second->route);
        $this->assertSame(1, $layer->ledger()->count(), 'one assignment per event');
    }

    /**
     * toObservation() → контракт GoldenCaseEvaluator: проверяемые поля без
     * reusable token; действие — чистые данные, никогда не применяется.
     */
    public function test_observation_contract_and_action_are_pure_data(): void
    {
        $service = new W1BotBindingPrepService;
        $result = $service->handle(
            'h6186:test:obs',
            'Как привязать мой Telegram к личному кабинету?',
            ['channel' => 'tg', 'binding_state' => 'unlinked'],
        );

        $observation = $result->toObservation();

        foreach ([
            'outcome',
            'draft',
            'route_to_human',
            'human_send_required',
            'steps_from_approved_checklist_only',
            'no_confident_guess',
            'forbidden_contour',
            'token_state',
            'binding_applied',
            'reusable_token_in_report_or_llm',
            'no_side_effects',
        ] as $key) {
            $this->assertArrayHasKey($key, $observation);
        }

        $this->assertTrue($observation['no_side_effects'], 'observation declares no side-effects');
        $this->assertFalse($observation['binding_applied'], 'binding is data, never applied');
        $this->assertFalse($observation['reusable_token_in_report_or_llm'], 'no reusable token in report or LLM');

        // Действие: шаги строго из утверждённого чек-листа, deep link как данные.
        $action = $result->action;
        $this->assertNotNull($action);
        $this->assertSame(OnboardingChecklist::bindingSteps(), $action->checklistSteps, 'action steps = approved checklist');
        $this->assertMatchesRegularExpression('#^https://t\.me/samskrtamru_bot\?start=\S+$#', (string) $action->deepLink);
        $this->assertStringNotContainsString((string) $action->deepLink, (string) json_encode($observation), 'deep link stays out of reports');
        $this->assertFalse(W1BotBindingPrepAction::APPLIED);
        $this->assertTrue(W1BotBindingPrepAction::FOR_HUMAN_OPERATOR_ONLY);
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
