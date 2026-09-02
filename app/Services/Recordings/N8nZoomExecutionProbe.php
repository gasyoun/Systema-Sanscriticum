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
     * @return array{reachable: bool, skipped: bool, id:?string, status:?string, started_at:?string, error_class:?string, note:?string, webhook_token:?string}
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
                webhookToken: $failed ? $this->webhookTokenState($blob, $timeout) : null,
            );
        } catch (Throwable $e) {
            Log::info('recordings:gap-watch n8n skip-soft', ['error' => $e->getMessage()]);

            return $this->pack(note: 'n8n skip-soft: '.$e->getMessage());
        }
    }

    /**
     * H3952: the ZOOM 1.4 assertion nodes stamp their verdict into the thrown error, so a
     * failed execution already carries the credential-vs-webhook answer — read it before
     * falling back to the generic OpenRouter classes. Marker constants are the contract
     * with the workflow's «Стоп: …» Code nodes; changing one means changing both.
     *
     * @var array<string, string>
     */
    private const H3952_MARKERS = [
        'h3952_credential_fetch_failure' => 'fresh_link_credential',
        'h3952_webhook_missing' => 'fresh_link_webhook_missing',
        'h3952_undecidable_3301' => 'fresh_link_undecidable',
        'h3952_account_unregistered' => 'fresh_link_account_unregistered',
        'h3952_replay_impossible' => 'fresh_link_replay_impossible',
    ];

    /**
     * Human-facing one-liner per H3952 class — what the duty agent should DO, not a label.
     *
     * @var array<string, string>
     */
    private const H3952_VERDICTS = [
        'fresh_link_credential' => 'сбой credential/fetch: запись жива, её не видит OAuth-cred аккаунта — чинить cred, запись достать вручную (Play B)',
        'fresh_link_webhook_missing' => 'вебхук не принёс запись и токен мёртв — класс «записи нет», НЕ сбой credential',
        'fresh_link_undecidable' => 'Zoom 3301 без живого токена: «чужой аккаунт» и «записи не было» неразличимы — смотреть облако Zoom глазами (Play B)',
        'fresh_link_account_unregistered' => 'Zoom-аккаунт не в реестре fresh-link — завести cred + строку реестра + пару нод',
        'fresh_link_replay_impossible' => 'подписанная ссылка истекла, аккаунт вне реестра — свежую ссылку взять неоткуда, доставлять вручную',
    ];

    public static function verdictFor(?string $class): ?string
    {
        return $class === null ? null : (self::H3952_VERDICTS[$class] ?? null);
    }

    public static function classify(?string $blob): string
    {
        $text = mb_strtolower((string) $blob);
        if ($text === '') {
            return 'other';
        }

        foreach (self::H3952_MARKERS as $marker => $class) {
            if (str_contains($text, $marker)) {
                return $class;
            }
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
     * @return array{reachable: bool, skipped: bool, id:?string, status:?string, started_at:?string, error_class:?string, note:?string, webhook_token:?string}
     */
    private function pack(
        bool $reachable = false,
        bool $skipped = false,
        ?string $id = null,
        ?string $status = null,
        ?string $startedAt = null,
        ?string $errorClass = null,
        ?string $note = null,
        ?string $webhookToken = null,
    ): array {
        return [
            'reachable' => $reachable,
            'skipped' => $skipped,
            'id' => $id,
            'status' => $status,
            'started_at' => $startedAt,
            'error_class' => $errorClass,
            'note' => $note,
            'webhook_token' => $webhookToken,
        ];
    }

    /**
     * H3952: the recorded incident's own discriminator — HEAD the signed webhook token URL
     * carried by the failed execution. A live token proves the recording still exists in
     * the Zoom cloud, so the failure is a credential/fetch problem rather than a missing
     * or deleted recording. Never fetches the body; a dead token is the normal outcome
     * past the ~24 h window, not an error worth surfacing.
     *
     * @return 'alive'|'dead'|'absent'|null
     */
    private function webhookTokenState(string $blob, int $timeout): ?string
    {
        // n8n hands the execution back as JSON, and PHP/JS both escape `/` as `\/` inside
        // it, so the signed URL never matches a naive pattern — unescape before searching.
        $flat = str_replace('\\/', '/', $blob);
        if (! preg_match('~https://[^"\s\\\\]+\?access_token=[^"\s\\\\]+~', $flat, $m)) {
            return 'absent';
        }

        try {
            $res = Http::timeout(min($timeout, 5))->head($m[0]);

            return $res->successful() || $res->redirect() ? 'alive' : 'dead';
        } catch (Throwable $e) {
            Log::info('recordings:gap-watch webhook-token HEAD failed', ['error' => $e->getMessage()]);

            return null;
        }
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
