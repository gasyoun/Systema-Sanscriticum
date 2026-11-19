<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Routing;

/**
 * Итог записи назначения: создано ли новое (единственный раз на событие)
 * либо возвращено существующее (повтор/петля — дубль запрещён).
 */
final readonly class W1RoutingAssignmentResult
{
    public function __construct(
        public W1RoutingAssignment $assignment,
        public bool $created,
        public bool $duplicateSuppressed,
        public bool $loopSuppressed,
        public bool $unknownRouteFallback,
    ) {}
}
