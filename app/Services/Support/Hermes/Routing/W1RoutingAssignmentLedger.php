<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Routing;

use App\Services\Support\Hermes\GoldenCaseEvaluator;

/**
 * Идемпотентный реестр назначений общего слоя W1 (H6190, пакет
 * hermes-24-01a10bca): «без циклов и дублей».
 *
 * Контракт:
 *  - назначение создаётся РОВНО ОДИН раз на ключ события (event key =
 *    идемпотентность доставки: повторная доставка того же входящего события,
 *    ретрай вебхука, повторный проход не создают второго назначения);
 *  - попытка переназначить уже назначенное событие (отскок роли назад в
 *    маршрутизацию, loop) ПОДАВЛЯЕТСЯ: возвращается исходное назначение;
 *  - неизвестная цель маршрута (вне канонического ростера V2) нормализуется
 *    в `human` ДО записи — ни одного назначения на несуществующую роль,
 *    ни одного нового канонического role ID;
 *  - только память процесса: слой ничего не шлёт и ничего не пишет в БД
 *    (production-включение — отдельный deploy-шаг человека).
 */
final class W1RoutingAssignmentLedger
{
    /** @var array<string, array{assignment: W1RoutingAssignment, attempts: int, loops: int}> */
    private array $entries = [];

    public function record(string $eventKey, W1RoutingDecision $decision): W1RoutingAssignmentResult
    {
        $trimmedKey = trim($eventKey);
        if ($trimmedKey === '') {
            throw new \InvalidArgumentException('W1 routing: event key must not be empty');
        }

        $unknownRouteFallback = ! in_array($decision->route, GoldenCaseEvaluator::ROUTE_TARGETS, true);
        if ($unknownRouteFallback) {
            $decision = new W1RoutingDecision(
                route: 'human',
                category: $decision->category,
                singlePass: true,
                noRoleLoop: $decision->noRoleLoop,
                noLlmDraft: true, // неизвестный маршрут не получает LLM-черновика
                directEscalation: true,
                reason: 'unknown_route_fallback:'.$decision->route,
            );
        }

        if (! isset($this->entries[$trimmedKey])) {
            $assignment = new W1RoutingAssignment(
                eventKey: $trimmedKey,
                decision: $decision,
                attemptCount: 1,
                loopSuppressedCount: 0,
            );
            $this->entries[$trimmedKey] = ['assignment' => $assignment, 'attempts' => 1, 'loops' => 0];

            return new W1RoutingAssignmentResult(
                assignment: $assignment,
                created: true,
                duplicateSuppressed: false,
                loopSuppressed: false,
                unknownRouteFallback: $unknownRouteFallback,
            );
        }

        $entry = &$this->entries[$trimmedKey];
        $entry['attempts']++;

        // Терминальность: первое назначение — итоговое. Расхождение решения
        // (петля: роль отскочила назад, повторный проход с другим вердиктом)
        // лишь подавляется и считается в loopSuppressedCount.
        $isLoop = ! $entry['assignment']->decision->sameOutcomeAs($decision);
        if ($isLoop) {
            $entry['loops']++;
        }

        $assignment = new W1RoutingAssignment(
            eventKey: $trimmedKey,
            decision: $entry['assignment']->decision,
            attemptCount: $entry['attempts'],
            loopSuppressedCount: $entry['loops'],
        );
        $entry['assignment'] = $assignment;

        return new W1RoutingAssignmentResult(
            assignment: $assignment,
            created: false,
            duplicateSuppressed: true,
            loopSuppressed: $isLoop,
            unknownRouteFallback: $unknownRouteFallback,
        );
    }

    public function has(string $eventKey): bool
    {
        return isset($this->entries[trim($eventKey)]);
    }

    public function get(string $eventKey): ?W1RoutingAssignment
    {
        return $this->entries[trim($eventKey)]['assignment'] ?? null;
    }

    /** @return list<W1RoutingAssignment> */
    public function assignments(): array
    {
        return array_values(array_map(
            static fn (array $entry): W1RoutingAssignment => $entry['assignment'],
            $this->entries,
        ));
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
