<?php

declare(strict_types=1);

namespace App\Services\Support\StudentAgent\Tools;

use App\Models\User;
use App\Services\Support\StudentAgent\StudentAgentService;

/**
 * One bounded job the student agent (H3231) may run. The allow-list itself
 * lives in {@see StudentAgentService} —
 * this interface only shapes what a registered tool looks like.
 */
interface StudentAgentTool
{
    public function name(): string;

    /**
     * True if running this tool changes durable state (submits, sends,
     * deletes, grants). All three W3 tools (homework hint / dictionary
     * lookup / cabinet FAQ) are read-only lookups and return false; the
     * value still gates every call so a future tool cannot skip CONFIRM
     * by accident.
     */
    public function isIrreversible(): bool;

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, reason?: string, data?: array<string, mixed>, tokens?: int}
     */
    public function run(User $user, array $params): array;
}
