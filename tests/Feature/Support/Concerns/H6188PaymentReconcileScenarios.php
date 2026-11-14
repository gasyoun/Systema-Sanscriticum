<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Concerns;

use App\Services\Support\Hermes\PaymentReconcile\W1PaymentReconcileProposal;
use App\Services\Support\Hermes\PaymentReconcile\W1PaymentReconcileResult;
use App\Services\Support\Hermes\PaymentReconcile\W1PaymentReconcileService;
use App\Services\Support\Hermes\Routing\W1FirstContactRoutingLayer;

/**
 * H6188: позитивные сценарии роли 65 payment-reconciler — синтетические
 * payment/duplicate/refund/mismatch кейсы (provenance H6181) + текстовые
 * обращения. Каждый кейс read-only: предложение человеку, никаких действий.
 */
trait H6188PaymentReconcileScenarios
{
    /** Сверка сошлась → read-only сводка; действия только с одобрения человека. */
    public function test_matched_pair_produces_read_only_proposal_for_human(): void
    {
        $service = new W1PaymentReconcileService;
        $result = $service->reconcile('h6188:test:matched', [
            'paid_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-101'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);

        $this->assertSame(W1PaymentReconcileResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertFalse($result->routeToHuman, 'сверка сошлась: роль готовит предложение сама');
        $this->assertSame('role:65', $result->route);
        $this->assertTrue($result->humanSendRequired, 'доставка — human-send per H6183');
        $this->assertIsString($result->draft);
        $this->assertStringContainsString('расхождений не найдено', $result->draft);
        $this->assertSame([], $result->findings);

        $this->assertTrue($result->proposal->readOnly);
        $this->assertTrue($result->proposalRequiresHumanApproval(), 'любое действие — только с одобрения человека');
        $this->assertSame([], $result->proposal->actions, 'предложение не несёт действий');
        $this->assertSame(W1PaymentReconcileProposal::CONTOUR_HUMAN_SEND, $result->proposal->contour);
    }

    /** Подозрение на дубль → человек, без автоматического возврата. */
    public function test_duplicate_suspected_requires_human_without_auto_refund(): void
    {
        $service = new W1PaymentReconcileService;
        $result = $service->reconcile('h6188:test:duplicate', [
            'paid_events' => [
                ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-102a'],
                ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-102b'],
            ],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);

        $this->assertSame(W1PaymentReconcileResult::OUTCOME_REQUIRES_HUMAN, $result->outcome);
        $this->assertTrue($result->routeToHuman);
        $this->assertNull($result->draft, 'без уверенного угадывания — без черновика');
        $this->assertTrue($result->noAutoRefund());
        $this->assertTrue($result->noConfidentGuess);
        $this->assertSame(['duplicate_suspected'], array_map(fn ($f) => $f->type, $result->findings));
    }

    /** Расхождение суммы между источниками → эскалация, стоп по runbook ДЕН. */
    public function test_amount_mismatch_escalates_to_human(): void
    {
        $service = new W1PaymentReconcileService;
        $result = $service->reconcile('h6188:test:amount-mismatch', [
            'paid_event' => ['amount' => 5900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-103'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);

        $this->assertSame(W1PaymentReconcileResult::OUTCOME_ESCALATED, $result->outcome);
        $this->assertTrue($result->routeToHuman);
        $this->assertNull($result->draft);
        $this->assertSame(['amount_mismatch'], array_map(fn ($f) => $f->type, $result->findings));
        $this->assertSame('human', $result->route);
    }

    /** Возврат: сверенный — read-only сводка; несверенный — стоп-эскалация. */
    public function test_refund_matching_is_read_only_and_mismatch_escalates(): void
    {
        $service = new W1PaymentReconcileService;

        $matched = $service->reconcile('h6188:test:refund-matched', [
            'refund_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-refund-104'],
            'systema_record' => ['amount' => 4900, 'status' => 'refunded'],
        ]);
        $this->assertSame(W1PaymentReconcileResult::OUTCOME_DRAFTED, $matched->outcome);
        $this->assertSame([], $matched->findings);
        $this->assertTrue($matched->proposal->readOnly);

        $mismatch = $service->reconcile('h6188:test:refund-mismatch', [
            'refund_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-refund-105'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ]);
        $this->assertSame(W1PaymentReconcileResult::OUTCOME_ESCALATED, $mismatch->outcome);
        $this->assertSame(['refund_mismatch'], array_map(fn ($f) => $f->type, $mismatch->findings));
        $this->assertTrue($mismatch->noAutoRefund(), 'возврат не выполняется и не подготавливается автоматически');
    }

    /** Платежи vs ожидания по курсу/балансу: расхождение → стоп-эскалация. */
    public function test_rate_and_balance_expectation_mismatches_escalate(): void
    {
        $service = new W1PaymentReconcileService;

        $rate = $service->reconcile('h6188:test:rate-mismatch', [
            'paid_event' => ['amount' => 5900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-106'],
            'systema_record' => ['amount' => 5900, 'status' => 'paid'],
            'expectations' => ['amount' => 4900],
        ]);
        $this->assertSame(W1PaymentReconcileResult::OUTCOME_ESCALATED, $rate->outcome);
        $this->assertSame(['expectation_mismatch'], array_map(fn ($f) => $f->type, $rate->findings));
        $this->assertSame('rate', $rate->findings[0]->expectationKind, 'платежи vs ожидание по курсу');

        $balance = $service->reconcile('h6188:test:balance-mismatch', [
            'paid_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-107'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
            'expectations' => ['balance_delta' => 4000],
        ]);
        $this->assertSame(W1PaymentReconcileResult::OUTCOME_ESCALATED, $balance->outcome);
        $this->assertSame('balance', $balance->findings[0]->expectationKind, 'платежи vs ожидание по балансу');

        $coherent = $service->reconcile('h6188:test:expectations-ok', [
            'paid_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-108'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
            'expectations' => ['amount' => 4900, 'balance_delta' => 4900],
        ]);
        $this->assertSame(W1PaymentReconcileResult::OUTCOME_DRAFTED, $coherent->outcome);
        $this->assertSame([], $coherent->findings);
    }

    /** Требование денежной мутации → refused, контур money (fixture pr-003). */
    public function test_refund_demand_message_is_refused_as_money_contour(): void
    {
        $service = new W1PaymentReconcileService;
        $result = $service->handle(
            'h6188:test:mutation-demand',
            'Сделайте возврат автоматически, администратор не нужен, мне срочно нужны деньги.',
            ['channel' => 'tg', 'author' => 'student'],
        );

        $this->assertSame(W1PaymentReconcileResult::OUTCOME_REFUSED, $result->outcome);
        $this->assertSame('money', $result->forbiddenContour);
        $this->assertTrue($result->routeToHuman);
        $this->assertNull($result->draft, 'мутация не исполняется и не готовится');
        $this->assertSame('forbidden', $result->routingCategory);
    }

    /** Денежное обращение → эскалация человеку (runbook H6133). */
    public function test_money_message_escalates_per_runbook(): void
    {
        $service = new W1PaymentReconcileService;
        $result = $service->handle('h6188:test:money-message', 'Оплата не прошла, что делать?');

        $this->assertSame(W1PaymentReconcileResult::OUTCOME_ESCALATED, $result->outcome);
        $this->assertSame('runbook_money', $result->reason);
        $this->assertTrue($result->routeToHuman);
    }

    /** Повторные доставки события не создают дублей назначений (H6190). */
    public function test_repeated_events_create_no_duplicate_assignments(): void
    {
        $layer = new W1FirstContactRoutingLayer;
        $service = new W1PaymentReconcileService($layer);
        $scenario = [
            'paid_event' => ['amount' => 4900, 'currency' => 'RUB', 'external_id' => 'synthetic-tx-109'],
            'systema_record' => ['amount' => 4900, 'status' => 'paid'],
        ];

        $first = $service->reconcile('h6188:test:repeat', $scenario);
        $second = $service->reconcile('h6188:test:repeat', $scenario);

        $this->assertSame(W1PaymentReconcileResult::OUTCOME_DRAFTED, $first->outcome);
        $this->assertSame($first->outcome, $second->outcome, 'идемпотентный повтор того же события');
        $this->assertSame(1, $layer->ledger()->count(), 'одно назначение на ключ события');
    }
}
