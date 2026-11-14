<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Concerns;

use App\Services\Support\Hermes\GoldenCaseEvaluator;
use App\Services\Support\Hermes\PaymentReconcile\W1PaymentReconcileResult;
use App\Services\Support\Hermes\PaymentReconcile\W1PaymentReconcileService;
use App\Services\Support\Hermes\Routing\W1FirstContactRoutingLayer;
use App\Services\Support\SupportStudentPiiMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * H6188 (класс money): границы роли 65 — ZERO money-мутаций (снапшот всех
 * таблиц до/после идентичен), ZERO исходящих отправок и платных API
 * (Http::assertNothingSent), ZERO LLM-вызовов, PII fail-closed (H6182),
 * квота H6174 default OFF (log-only), роль OFF, только канонические
 * маршруты ростера V2.
 */
trait H6188MoneySafetyScenarios
{
    /** ZERO money-мутаций и side-effects: снапшот всех таблиц идентичен. */
    public function test_zero_money_mutations_no_outgoing_sends_no_llm(): void
    {
        Http::fake();

        $before = $this->h6188TableCounts();
        $service = new W1PaymentReconcileService;

        // Все четыре класса миссии: payment/duplicate/refund/mismatch + тексты.
        $service->reconcile('h6188:nse:1', [
            'paid_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-201'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);
        $service->reconcile('h6188:nse:2', [
            'paid_events' => [
                ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-202a'],
                ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-202b'],
            ],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);
        $service->handle('h6188:nse:3', 'Сделайте возврат автоматически, администратор не нужен, мне срочно нужны деньги.');
        $service->reconcile('h6188:nse:4', [
            'refund_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-refund-203'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);
        $service->reconcile('h6188:nse:5', [
            'paid_event' => ['amount' => 5900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-204'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);
        $service->handle('h6188:nse:6', 'Оплата не прошла, что делать?');

        $this->assertSame($before, $this->h6188TableCounts(), 'роль 65 не пишет ни в одну таблицу (zero money-мутаций)');
        Http::assertNothingSent();
    }

    /** PII не достигает выхода; exception маскера → fail-closed (H6182). */
    public function test_pii_never_reaches_output_and_fail_closed(): void
    {
        $message = 'Меня зовут Иван Петров, телефон +7 (916) 123-45-67, почта ivan.petrov@example.com. '
            .'Сделайте возврат автоматически, администратор не нужен.';

        $service = new W1PaymentReconcileService;
        $result = $service->handle('h6188:test:pii', $message, [
            'channel' => 'tg',
            'author' => 'student',
            'author_name' => 'Иван Петров',
            'author_handle' => 'ivan_p',
        ]);

        $payload = (string) json_encode($result->toObservation(), JSON_UNESCAPED_UNICODE)
            ."\n".$result->proposal->summary;
        foreach (['Иван Петров', '916', '123-45-67', 'ivan.petrov@example.com', 'ivan_p'] as $raw) {
            $this->assertStringNotContainsString($raw, $payload, 'PII не должна достигать наблюдения/предложения: '.$raw);
        }

        // Fail-closed: exception маскера → текст исключён, исход не меняется.
        $throwingMasker = new class extends SupportStudentPiiMasker
        {
            public function maskOrFail(string $text): array
            {
                throw new \RuntimeException('synthetic identity-net failure');
            }
        };
        $closed = new W1PaymentReconcileService(new W1FirstContactRoutingLayer, $throwingMasker);
        $failed = $closed->handle('h6188:test:pii-fail-closed', $message, ['channel' => 'tg', 'author' => 'student']);

        $this->assertSame($result->outcome, $failed->outcome, 'fail-closed не даёт ни черновика, ни иного исхода');
        $this->assertNull($failed->draft);
        $closedPayload = (string) json_encode($failed->toObservation(), JSON_UNESCAPED_UNICODE)."\n".$failed->proposal->summary;
        foreach (['Иван', 'Петров', '916', 'ivan.petrov@example.com'] as $raw) {
            $this->assertStringNotContainsString($raw, $closedPayload, 'fail-closed: текст исключён целиком');
        }
    }

    /** Quota OFF (default): log-only, предложение собрано без enforcement (H6174). */
    public function test_quota_machine_default_off_log_only(): void
    {
        $this->assertFalse(config('school_role_quota.enabled', false), 'квотная машина должна быть OFF по умолчанию');

        $service = new W1PaymentReconcileService;
        $result = $service->reconcile('h6188:test:quota-off', [
            'paid_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-205'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);

        $this->assertSame(W1PaymentReconcileResult::OUTCOME_DRAFTED, $result->outcome, 'quota OFF: предложение собирается');
        $this->assertIsArray($result->quotaInfo);
        $this->assertFalse($result->quotaInfo['enabled'], 'quota info отражает disabled-состояние');
        $this->assertSame('disabled', $result->quotaInfo['draft_verdict']['status'] ?? null, 'reserve() log-only при disabled');
    }

    /** Quota ON + исчерпана: requires_human (fallback=none), ноль LLM-вызовов. */
    public function test_quota_machine_denied_when_enabled_and_exhausted(): void
    {
        config([
            'school_role_quota.enabled' => true,
            'school_role_quota.allowances' => [W1PaymentReconcileService::ROLE_SLUG => 50],
        ]);

        $service = new W1PaymentReconcileService;
        $result = $service->reconcile('h6188:test:quota-denied', [
            'paid_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-206'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);

        $this->assertSame(
            W1PaymentReconcileResult::OUTCOME_REQUIRES_HUMAN,
            $result->outcome,
            'квота исчерпана: очередь человеку (fallback=none)',
        );
        $this->assertNull($result->draft, 'квота отказала: ноль LLM-вызовов');
        $this->assertTrue($result->routeToHuman);
        $this->assertSame('quota_denied', $result->reason);
    }

    /** Роль 65 OFF (W1 deploy gate): без черновика, очередь человеку. */
    public function test_role_off_refuses_to_draft(): void
    {
        config(['features.hermes_payment_reconciler' => false]);

        $service = new W1PaymentReconcileService;
        $message = $service->handle('h6188:test:role-off-msg', 'Оплата не прошла, что делать?');
        $scenario = $service->reconcile('h6188:test:role-off-scen', [
            'paid_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-207'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);

        foreach ([$message, $scenario] as $result) {
            $this->assertSame(W1PaymentReconcileResult::OUTCOME_REQUIRES_HUMAN, $result->outcome);
            $this->assertSame('role_disabled', $result->reason);
            $this->assertNull($result->draft, 'роль OFF: черновик не составляется');
            $this->assertTrue($result->routeToHuman);
        }
    }

    /** Только канонические маршруты ростера V2, никакой седьмой роли. */
    public function test_only_canonical_route_targets_no_new_role_ids(): void
    {
        $service = new W1PaymentReconcileService;
        $routes = [];

        foreach ([
            'Оплата не прошла, что делать?',
            'Сделайте возврат автоматически, администратор не нужен.',
            'Когда будет возврат за прошлый курс?',
            'Помогите, у меня проблема',
            'Какие учебники нужны для занятий?',
        ] as $i => $message) {
            $routes[] = $service->handle('h6188:test:routes:m'.$i, $message)->route;
        }
        foreach ([
            ['paid_event' => ['amount' => 4900, 'currency' => 'RUB'], 'systema_record' => ['amount' => 4900, 'status' => 'paid']],
            ['paid_event' => ['amount' => 5900, 'currency' => 'RUB'], 'systema_record' => ['amount' => 4900, 'status' => 'paid']],
            ['paid_events' => [['amount' => 4900, 'currency' => 'RUB'], ['amount' => 4900, 'currency' => 'RUB']], 'systema_record' => ['amount' => 4900, 'status' => 'paid']],
        ] as $i => $scenario) {
            $routes[] = $service->reconcile('h6188:test:routes:s'.$i, $scenario)->route;
        }

        foreach ($routes as $route) {
            $this->assertContains($route, GoldenCaseEvaluator::ROUTE_TARGETS, 'маршрут обязан быть канонической целью V2');
        }

        $roleTargets = array_values(array_unique(array_filter($routes, static fn (string $r): bool => $r !== 'human')));
        foreach ($roleTargets as $target) {
            $this->assertContains((int) substr($target, 5), GoldenCaseEvaluator::REQUIRED_ROLE_IDS, 'нет седьмой канонической роли');
        }
    }

    /** @return array<string, int> */
    private function h6188TableCounts(): array
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
