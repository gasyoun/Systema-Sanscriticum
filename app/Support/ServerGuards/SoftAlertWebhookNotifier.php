<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Outbound soft-alert webhook (H2148 C) — fail-open, default off.
 *
 * POST JSON to n8n / GitHub Actions / agent runner. Never throws into cabinet:probe.
 */
final class SoftAlertWebhookNotifier
{
    /**
     * @param  list<array{message: string, severity?: string}>  $softFails
     * @return array{attempted: bool, ok: bool, status: int|null, detail: string}
     */
    public function notify(string $scope, string $fingerprint, array $softFails): array
    {
        $url = trim((string) config('cabinet_probe.soft_webhook_url', ''));
        if ($url === '') {
            return ['attempted' => false, 'ok' => false, 'status' => null, 'detail' => 'SOFT_ALERT_WEBHOOK_URL empty'];
        }

        $secret = (string) config('cabinet_probe.soft_webhook_secret', '');
        $timeout = max(1, (int) config('cabinet_probe.soft_webhook_timeout', 8));

        $payload = [
            'event' => 'cabinet.soft_alert',
            'schema_version' => 1,
            'scope' => $scope,
            'fingerprint' => $fingerprint,
            'app_url' => (string) config('app.url', ''),
            'host_hint' => '193.232.229.92',
            'playbook' => 'docs/SERVER_SOFT_ALERT_PLAYBOOK.md',
            'suggested' => [
                'diagnose' => 'php artisan ops:soft-remediate --dry-run --json',
                'safe_apply' => 'php artisan ops:soft-remediate --apply --apply-breaker-clear',
                'verify' => 'php artisan guards:verify && php artisan cabinet:probe',
            ],
            'failures' => array_map(
                static fn (array $f): array => [
                    'message' => (string) ($f['message'] ?? ''),
                    'severity' => (string) ($f['severity'] ?? 'soft'),
                ],
                array_slice($softFails, 0, 20),
            ),
            'occurred_at' => now()->utc()->toIso8601String(),
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'Systema-cabinet-probe-soft-webhook/1',
        ];
        if ($secret !== '') {
            $headers['X-Soft-Alert-Secret'] = $secret;
        }

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->post($url, $payload);
            $ok = $response->successful();
            if (! $ok) {
                Log::warning('cabinet:probe soft webhook non-2xx', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 200),
                ]);
            }

            return [
                'attempted' => true,
                'ok' => $ok,
                'status' => $response->status(),
                'detail' => $ok ? 'webhook ok' : 'webhook HTTP '.$response->status(),
            ];
        } catch (Throwable $e) {
            Log::warning('cabinet:probe soft webhook failed', ['error' => $e->getMessage()]);

            return [
                'attempted' => true,
                'ok' => false,
                'status' => null,
                'detail' => 'webhook exception: '.$e->getMessage(),
            ];
        }
    }
}
