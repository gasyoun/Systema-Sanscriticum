<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Routing;

/**
 * Одно назначение общего слоя маршрутизации W1 (H6190).
 *
 * Назначение — терминально и ровно одно на событие: повторные доставки,
 * ретраи вебхуков, отскоки ролей (loop) и неизвестные маршруты не создают
 * второго назначения (миссия H6190: «без циклов и дублей»).
 */
final readonly class W1RoutingAssignment
{
    public function __construct(
        public string $eventKey,
        public W1RoutingDecision $decision,
        public int $attemptCount,
        public int $loopSuppressedCount,
    ) {}
}
