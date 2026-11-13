<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Routing;

/**
 * Общий слой маршрутизации первого контакта W1 (H6190, пакет
 * hermes-24-01a10bca): единая точка входа «без циклов и дублей».
 *
 * Назначение слоя — решить, КТО занимается первым контактом (одна из шести
 * канонических ролей ростера V2 либо человек), и записать назначение
 * идемпотентно. Это НЕ новый канонический role ID и НЕ параллельный механизм:
 * классификация переиспользует SupportQuestionCategorizer/TechnicalIssueDetector,
 * а цели ограничены каноном GoldenCaseEvaluator::ROUTE_TARGETS.
 *
 * Жёсткие границы (миссия H6190 + exclusions):
 *  - никаких исходящих сообщений любого вида — слой не имеет отправщиков;
 *  - никаких записей в прод-таблицы и LLM-вызовов — только память процесса;
 *  - деньги/конфликт по принятому runbook эскалируются человеку;
 *  - повторные события, петли и неизвестные маршруты не создают дублей
 *    назначений (W1RoutingAssignmentLedger).
 *
 * Потребитель — first-contact роль 13 (H6184): она вызывает слой и дальше
 * работает только со своим решением; human-очередь — за человеком
 * (первые 30 дней human-send, H6183).
 */
final class W1FirstContactRoutingLayer
{
    public function __construct(
        private readonly W1FirstContactRouter $router = new W1FirstContactRouter,
        private readonly W1RoutingAssignmentLedger $ledger = new W1RoutingAssignmentLedger,
    ) {}

    /**
     * Классифицировать первое контакта и записать назначение (один раз).
     *
     * @param  array<string, mixed>  $context  машиночитаемый контекст события
     */
    public function routeAndAssign(string $eventKey, string $message, array $context = []): W1RoutingAssignmentResult
    {
        return $this->assignDecision($eventKey, $this->router->decide($message, $context));
    }

    /**
     * Записать уже принятое решение (внешний производитель, например
     * делегирование роли). Неизвестная цель нормализуется в `human`.
     */
    public function assignDecision(string $eventKey, W1RoutingDecision $decision): W1RoutingAssignmentResult
    {
        return $this->ledger->record($eventKey, $decision);
    }

    public function ledger(): W1RoutingAssignmentLedger
    {
        return $this->ledger;
    }

    public function router(): W1FirstContactRouter
    {
        return $this->router;
    }
}
