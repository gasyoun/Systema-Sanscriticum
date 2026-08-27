<?php

declare(strict_types=1);

namespace App\Services\Support\StudentAgent;

use App\Models\User;
use App\Services\Support\StudentAgent\Tools\CabinetFaqTool;
use App\Services\Support\StudentAgent\Tools\DictionaryLookupTool;
use App\Services\Support\StudentAgent\Tools\HomeworkHintTool;
use App\Services\Support\StudentAgent\Tools\StudentAgentTool;

/**
 * Bounded student agent (H3231, Wave 3 of the agent-ops overlay). Exactly
 * three jobs, hard tool allow-list, CONFIRM required before any irreversible
 * tool would run. Deliberately NOT a free-form chat tutor: there is no
 * "ask anything" path, only these three named tools — anything else is
 * refused before any LLM is touched. features.student_agent is OFF by
 * default (deploy switch); flipping it ON is a human step, not this code's.
 */
final class StudentAgentService
{
    /**
     * @var array<string, StudentAgentTool>
     */
    private array $tools;

    /**
     * @param  list<StudentAgentTool>|null  $tools  Injected in tests to probe the
     *                                              CONFIRM gate with a fake irreversible tool; production always uses the
     *                                              three real tools resolved from the container.
     */
    public function __construct(
        DictionaryLookupTool $dictionaryLookup,
        CabinetFaqTool $cabinetFaq,
        HomeworkHintTool $homeworkHint,
        ?array $tools = null,
    ) {
        $tools ??= [$homeworkHint, $dictionaryLookup, $cabinetFaq];

        $this->tools = [];
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) config('features.student_agent', false);
    }

    /**
     * @return list<string>
     */
    public function allowedTools(): array
    {
        return array_keys($this->tools);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, reason?: string, data?: array<string, mixed>, requires_confirmation?: bool, budget?: array<string, mixed>}
     */
    public function handle(User $user, string $tool, array $params, bool $confirmed = false): array
    {
        $start = microtime(true);

        if (! $this->isEnabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }

        if (! isset($this->tools[$tool])) {
            // Out-of-scope ask: refused outright, never reaches CONFIRM or an LLM.
            return ['ok' => false, 'reason' => 'tool_not_allowed'];
        }

        $handler = $this->tools[$tool];

        if ($handler->isIrreversible() && ! $confirmed) {
            return ['ok' => false, 'reason' => 'confirmation_required', 'requires_confirmation' => true];
        }

        $result = $handler->run($user, $params);

        $result['budget'] = AgentBudget::snapshot(
            steps: 1,
            maxSteps: 1,
            seconds: round(microtime(true) - $start, 3),
            maxSeconds: 30.0,
            tokens: $result['tokens'] ?? null,
            maxTokens: 800,
            costUsd: null,
            maxCostUsd: null,
            costEvaluable: false,
        );
        unset($result['tokens']);

        return $result;
    }
}
