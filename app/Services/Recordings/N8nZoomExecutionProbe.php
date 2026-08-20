<?php

declare(strict_types=1);

namespace App\Services\Recordings;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only last execution of live ZOOM 1.4 TEST. Never retries the workflow.
 */
final class N8nZoomExecutionProbe
{
    /**
     * @return array{reachable: bool, skipped: bool, id:?string, status:?string, started_at:?string, error_class:?string, note:?string}
     */
    public function lastLiveZoomExecution(): array
    {
        $base = (string) config('recording_gap.n8n_api_base', '');
        $key = (string) config('recording_gap.n8n_api_key', '');
        $workflowId = (string) config('recording_gap.n8n_workflow_id', '1EIqqNzMl5NNIxST');

        if ($base === '' || $key === '') {
            return $this->pack(skipped: true, note: 'n8n skip-soft: N8N_API_KEY or N8N_API_BASE_URL empty');
        }

        $timeout = max(2, (int) config('recording_gap.n8n_timeout', 8));

        try {
            $list = Http::timeout($timeout)
                ->withHeaders(['X-N8N-API-KEY' => $key, 'Accept' => 'application/json'])
                ->get($base.'/api/v1/executions', [
                    'workflowId' => $workflowId,
                    'limit' => 3,
                ]);

            if (! $list->successful()) {
                return $this->pack(note: 'n8n HTTP '.$list->status());
            }

            $row = $this->firstExecution($list->json());
            if ($row === null) {
                return $this->pack(reachable: true, skipped: false, note: 'n8n: no executions for '.$workflowId);
            }

            $blob = json_encode($row, JSON_UNESCAPED_UNICODE) ?: '';
            $status = (string) ($row['status'] ?? '');
            $failed = in_array($status, ['error', 'crashed', 'failed'], true);

            if ($failed && isset($row['id'])) {
                $detail = $this->executionBlob($base, $key, $timeout, (string) $row['id']);
                if ($detail !== '') {
                    $blob .= "\n".$detail;
                }
            }

            $class = self::classify($blob);
            if (! $failed && $class === 'other') {
                $class = null;
            }

            return $this->pack(
                reachable: true,
                skipped: false,
                id: isset($row['id']) ? (string) $row['id'] : null,
                status: $status !== '' ? $status : null,
                startedAt: isset($row['startedAt']) ? (string) $row['startedAt'] : null,
                errorClass: $class,
            );
        } catch (Throwable $e) {
            Log::info('recordings:gap-watch n8n skip-soft', ['error' => $e->getMessage()]);

            return $this->pack(note: 'n8n skip-soft: '.$e->getMessage());
        }
    }

    public static function classify(?string $blob): string
    {
        $text = mb_strtolower((string) $blob);
        if ($text === '') {
            return 'other';
        }

        if (str_contains($text, 'more credits')
            || str_contains($text, 'can only afford')
            || str_contains($text, 'fewer max_tokens')
            || str_contains($text, '"httpcode":402')
            || str_contains($text, '"httpcode":"402"')) {
            return 'credits';
        }

        if (str_contains($text, 'terms of service')
            || str_contains($text, 'prohibited due to a violation')) {
            return 'tos_forbidden';
        }

        return 'other';
    }

    /**
     * @return array{reachable: bool, skipped: bool, id:?string, status:?string, started_at:?string, error_class:?string, note:?string}
     */
    private function pack(
        bool $reachable = false,
        bool $skipped = false,
        ?string $id = null,
        ?string $status = null,
        ?string $startedAt = null,
        ?string $errorClass = null,
        ?string $note = null,
    ): array {
        return [
            'reachable' => $reachable,
            'skipped' => $skipped,
            'id' => $id,
            'status' => $status,
            'started_at' => $startedAt,
            'error_class' => $errorClass,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstExecution(mixed $json): ?array
    {
        if (! is_array($json)) {
            return null;
        }
        $rows = $json['data']['results'] ?? $json['data'] ?? $json;
        if (! is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                return $row;
            }
        }

        return null;
    }

    private function executionBlob(string $base, string $key, int $timeout, string $id): string
    {
        try {
            $res = Http::timeout($timeout)
                ->withHeaders(['X-N8N-API-KEY' => $key, 'Accept' => 'application/json'])
                ->get($base.'/api/v1/executions/'.$id, ['includeData' => true]);

            return $res->successful() ? $res->body() : '';
        } catch (Throwable) {
            return '';
        }
    }
}
