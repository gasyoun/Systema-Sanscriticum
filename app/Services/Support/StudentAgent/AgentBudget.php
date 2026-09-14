<?php

declare(strict_types=1);

namespace App\Services\Support\StudentAgent;

/**
 * Four-budget snapshot (H3231, Wave 3 of the agent-ops overlay). HTTP-less PHP
 * mirror of Uprava tools/agent_ops/budget.py — steps/time/tokens/cost, missing
 * axes are null (unknown), never 0 (measured-zero). No Python sidecar in prod.
 */
final class AgentBudget
{
    /**
     * @return array{steps: ?int, max_steps: ?int, seconds: ?float, max_seconds: ?float, tokens: ?int, max_tokens: ?int, cost_usd: ?float, max_cost_usd: ?float, cost_evaluable: ?bool}
     */
    public static function snapshot(
        ?int $steps = null,
        ?int $maxSteps = null,
        ?float $seconds = null,
        ?float $maxSeconds = null,
        ?int $tokens = null,
        ?int $maxTokens = null,
        ?float $costUsd = null,
        ?float $maxCostUsd = null,
        ?bool $costEvaluable = null,
    ): array {
        $usd = $costEvaluable === false ? null : $costUsd;

        return [
            'steps' => $steps,
            'max_steps' => $maxSteps,
            'seconds' => $seconds,
            'max_seconds' => $maxSeconds,
            'tokens' => $tokens,
            'max_tokens' => $maxTokens,
            'cost_usd' => $usd,
            'max_cost_usd' => $maxCostUsd,
            'cost_evaluable' => $costEvaluable,
        ];
    }

    /**
     * 80% text when any *known* ratio (used/cap) crosses the threshold. Axes
     * with no cap (null) are skipped, not treated as 0% used.
     *
     * @param  array{steps: ?int, max_steps: ?int, seconds: ?float, max_seconds: ?float, tokens: ?int, max_tokens: ?int, cost_usd: ?float, max_cost_usd: ?float}  $snapshot
     */
    public static function softWarning(array $snapshot, float $threshold = 0.8): ?string
    {
        $ratios = [
            self::ratio($snapshot['steps'] ?? null, $snapshot['max_steps'] ?? null),
            self::ratio($snapshot['seconds'] ?? null, $snapshot['max_seconds'] ?? null),
            self::ratio($snapshot['tokens'] ?? null, $snapshot['max_tokens'] ?? null),
            self::ratio($snapshot['cost_usd'] ?? null, $snapshot['max_cost_usd'] ?? null),
        ];
        $known = array_values(array_filter($ratios, static fn (?float $r) => $r !== null));

        if ($known === [] || max($known) < $threshold) {
            return null;
        }

        return 'Budget is past 80% on at least one known axis.';
    }

    private static function ratio(int|float|null $used, int|float|null $cap): ?float
    {
        if ($used === null || $cap === null || (float) $cap <= 0.0) {
            return null;
        }

        return (float) $used / (float) $cap;
    }
}
