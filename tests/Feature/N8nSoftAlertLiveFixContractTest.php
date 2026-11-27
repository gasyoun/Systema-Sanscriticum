<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * H8562 live-fix contract additions (27-11-2026, executions 17392/17395):
 * shapes discovered broken in real n8n and re-pinned. Split from
 * N8nSoftAlertWorkflowContractTest to stay under the token-size gate.
 */
final class N8nSoftAlertLiveFixContractTest extends TestCase
{
    private const EXPORT = 'docs/n8n/soft-alert-to-github-issue.workflow.json';

    /** @return array<string, array<string, mixed>> */
    private function nodesByName(): array
    {
        $export = json_decode((string) file_get_contents(base_path(self::EXPORT)), true);
        $byName = [];
        foreach ((array) ($export['nodes'] ?? []) as $node) {
            $byName[(string) $node['name']] = $node;
        }

        return $byName;
    }

    /** Live-fix 27-11-2026 (exec 17392 "Credentials not found"): importable as-is — env-token headers, no UI credential refs. */
    public function test_github_auth_is_env_token_headers_not_credential_refs(): void
    {
        $byName = $this->nodesByName();
        $githubNodes = ['GitHub list open issues', 'GitHub comment on existing', 'GitHub create issue',
            'GitHub reconcile list', 'GitHub comment duplicate note', 'GitHub close duplicate',
            'Sweep list open issues', 'Sweep close duplicate', 'Sweep comment duplicate'];
        foreach ($githubNodes as $name) {
            $raw = (string) json_encode($byName[$name] ?? []);
            $this->assertStringNotContainsString('predefinedCredentialType', $raw, "{$name}: no UI credential references");
            $this->assertStringNotContainsString('githubApi', $raw, "{$name}: no githubApi credential type");
            $this->assertStringContainsString('Bearer {{$env.SOFT_ALERT_GITHUB_TOKEN}}', $raw, "{$name}: env-token Authorization header");
            $this->assertStringContainsString('systema-n8n-soft-alert', $raw, "{$name}: pinned User-Agent");
        }
    }

    /** Live-fix 27-11-2026 (exec 17395): a boolean IF without value2 is always true in real n8n — pin the number comparison. */
    public function test_duplicate_if_is_a_number_comparison_not_a_valueless_boolean(): void
    {
        $node = $this->nodesByName()['IF we are the duplicate'] ?? [];
        $conditions = (array) ($node['parameters']['conditions'] ?? []);
        $this->assertArrayHasKey('number', $conditions, 'duplicate IF must be a number comparison');
        $this->assertArrayNotHasKey('boolean', $conditions, 'a valueless boolean condition is always true in real n8n');
        $cond = $conditions['number'][0] ?? [];
        $this->assertSame('={{ $json.rivals_count }}', $cond['value1'] ?? null);
        $this->assertSame('larger', $cond['operation'] ?? null);
        $this->assertSame(0, $cond['value2'] ?? null, 'duplicate branch only when a rival exists');
    }
}
