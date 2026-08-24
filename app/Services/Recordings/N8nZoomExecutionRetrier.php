<?php

declare(strict_types=1);

namespace App\Services\Recordings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opt-in retry lane for n8n ZOOM 1.4 executions that died BEFORE any
 * download/upload node ran (the 23-08-2026 class: ECONNRESET / proxy death
 * on "Get row(s) in sheet"). Full replay of such an execution cannot
 * duplicate YouTube/Rutube uploads because nothing was uploaded yet.
 *
 * Hard guards, every one independent:
 *  - caller passes --retry-failed AND config retry_enabled is true;
 *  - execution finished with status=error inside the widened lesson window;
 *  - NO successful execution exists whose retryOf points at it;
 *  - we have not retried this id before (cache marker);
 *  - its runData touches only pre-upload nodes.
 *
 * Late failures (AI Agent, uploads) stay human-only: resume from AI Agent1.
 */
final class N8nZoomExecutionRetrier
{
    public const CACHE_PREFIX = 'recording_gap:n8n_retried:';

    private const RETRY_CACHE_TTL_DAYS = 30;

    /**
     * Failed executions in [from, until] (UTC) with retry-safety verdicts.
     *
     * @return list<array{id: string, started_at: ?string, last_node: ?string, safe: bool, superseded: bool, retried_before: bool, error_class: ?string}>
     */
    public function failedInWindow(CarbonImmutable $fromUtc, CarbonImmutable $untilUtc): array
    {
        $base = (string) config('recording_gap.n8n_api_base', '');
        $key = (string) config('recording_gap.n8n_api_key', '');
        $workflowId = (string) config('recording_gap.n8n_workflow_id', '1EIqqNzMl5NNIxST');

        if ($base === '' || $key === '') {
            return [];
        }

        $timeout = max(2, (int) config('recording_gap.n8n_timeout', 8));

        try {
            $errors = $this->listExecutions($base, $key, $timeout, $workflowId, 'error');
            $successes = $this->listExecutions($base, $key, $timeout, $workflowId, 'success');
        } catch (Throwable $e) {
            Log::info('recordings:gap-watch retry skip-soft', ['error' => $e->getMessage()]);

            return [];
        }

        $superseding = [];
        foreach ($successes as $row) {
            foreach ((array) ($row['retryOf'] ?? []) as $origin) {
                $superseding[(string) $origin] = true;
            }
            if (isset($row['retrySuccessId'])) {
                $superseding[(string) $row['retrySuccessId']] = true;
            }
        }

        $rows = [];
        foreach ($errors as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $started = isset($row['startedAt']) ? CarbonImmutable::parse((string) $row['startedAt'], 'UTC') : null;
            if ($started === null || $started->lt($fromUtc) || $started->gt($untilUtc)) {
                continue;
            }
            if (isset($row['retryOf']) && $row['retryOf'] !== null && $row['retryOf'] !== []) {
                continue;
            }

            [$safe, $lastNode] = $this->classifySafety($base, $key, $timeout, $id);
            $rows[] = [
                'id' => $id,
                'started_at' => $started->toIso8601String(),
                'last_node' => $lastNode,
                'safe' => $safe,
                'superseded' => isset($superseding[$id]),
                'retried_before' => Cache::has(self::CACHE_PREFIX.$id),
                'error_class' => N8nZoomExecutionProbe::classify(json_encode($row, JSON_UNESCAPED_UNICODE) ?: ''),
            ];
        }

        return $rows;
    }

    /**
     * POST one retry. Returns whether n8n accepted it.
     *
     * @return array{ok: bool, http: int, note: ?string}
     */
    public function retry(string $executionId): array
    {
        $base = (string) config('recording_gap.n8n_api_base', '');
        $key = (string) config('recording_gap.n8n_api_key', '');
        $timeout = max(2, (int) config('recording_gap.n8n_timeout', 8));

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['X-N8N-API-KEY' => $key, 'Accept' => 'application/json'])
                ->post($base.'/api/v1/executions/'.$executionId.'/retry');
        } catch (Throwable $e) {
            return ['ok' => false, 'http' => 0, 'note' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'http' => $response->status(), 'note' => mb_substr($response->body(), 0, 200)];
        }

        Cache::put(
            self::CACHE_PREFIX.$executionId,
            true,
            now()->addDays(self::RETRY_CACHE_TTL_DAYS),
        );
        Log::info('recordings:gap-watch retry posted', ['execution_id' => $executionId]);

        return ['ok' => true, 'http' => $response->status(), 'note' => null];
    }

    /**
     * Full-retry is safe iff every already-executed node is a known
     * pre-upload node. Empty/unknown runData counts as safe: nothing ran.
     *
     * @param  list<string>  $runDataKeys
     */
    public static function isRunDataSafe(array $runDataKeys): bool
    {
        $allowed = (array) config('recording_gap.retry_safe_early_nodes', []);

        foreach ($runDataKeys as $node) {
            if (! in_array((string) $node, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: bool, 1: ?string} safe, lastNodeExecuted
     */
    private function classifySafety(string $base, string $key, int $timeout, string $id): array
    {
        try {
            $detail = Http::timeout($timeout)
                ->withHeaders(['X-N8N-API-KEY' => $key, 'Accept' => 'application/json'])
                ->get($base.'/api/v1/executions/'.$id, ['includeData' => 'true']);

            if (! $detail->successful()) {
                return [false, null];
            }

            $payload = $detail->json('data.resultData.runData');
            if (! is_array($payload)) {
                return [false, null];
            }

            $keys = array_map('strval', array_keys($payload));
            $lastNode = $detail->json('data.resultData.lastNodeExecuted');

            return [self::isRunDataSafe($keys), is_string($lastNode) ? $lastNode : null];
        } catch (Throwable) {
            return [false, null];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listExecutions(string $base, string $key, int $timeout, string $workflowId, string $status): array
    {
        $response = Http::timeout($timeout)
            ->withHeaders(['X-N8N-API-KEY' => $key, 'Accept' => 'application/json'])
            ->get($base.'/api/v1/executions', [
                'workflowId' => $workflowId,
                'status' => $status,
                'limit' => 50,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('n8n HTTP '.$response->status().' on status='.$status);
        }

        $rows = $response->json('data.results') ?? $response->json('data') ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
