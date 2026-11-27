<?php

declare(strict_types=1);

namespace Tests\Support\N8n;

/**
 * Grammar pins + PHP mirrors for the H8562 workflow export (H8562 v3).
 *
 * Code-node JavaScript and jsonBody templates from the export are mirrored
 * here behind strict pins: if the exported text stops containing a pinned
 * fragment, every replay fails loudly with a WorkflowGlueException naming
 * the missing piece — the mirrors never guess.
 */
final class SoftAlertWorkflowPins
{
    /**
     * Full fingerprint extraction, mirroring the exported fpOf one-for-one:
     * a single alternation regex over the body — the structured marker or
     * the legacy **Fingerprint:** line, whichever appears first in the text
     * — lowercased; null when the body proves nothing (ambiguous legacy:
     * never matched, never closed).
     *
     * @param  array<string, mixed>  $issue
     */
    private function extractFingerprint(array $issue): ?string
    {
        $body = (string) ($issue['body'] ?? '');

        if (preg_match('/<!--\s*soft-alert-fingerprint:([0-9a-fA-F]{8,128})\s*-->|\*\*Fingerprint:\*\*\s*`([0-9a-fA-F]{8,128})`/', $body, $m) === 1) {
            return strtolower($m[1] !== '' ? $m[1] : $m[2]);
        }

        return null;
    }

    public function __construct(private readonly SoftAlertWorkflowExpressions $expressions) {}

    /**
     * Create node: pinned shape — title comes from "Match exact title"
     * (single title source), labels/assignees are the issue contract.
     * Returns [title, body] for the mock; the replay records the number.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: string}
     */
    public function parseCreateIssue(string $rawJsonBody, array $context): array
    {
        $pattern = '/^=\{\{ JSON\.stringify\(\{ title: \$\(\'Match exact title\'\)\.first\(\)\.json\.expected_title,'
            .' body: `(.+)`, labels: \[\'ops-soft-alert\'\], assignees: \[\'pe4kinsmart-tech\'\] \}\) \}\}$/s';

        if (preg_match($pattern, trim($rawJsonBody), $m) !== 1) {
            throw new WorkflowGlueException(
                'GitHub create issue jsonBody no longer matches the pinned H8562 shape '
                .'(title from Match exact title + labels/assignees contract). '
                .'Update tests/Support/N8n/ mirrors or revert the export.'
            );
        }

        // Failed-unit detection must survive every export edit: the issue
        // body keeps the failures list, playbook link and the suggested
        // diagnose command (H8562 scope guard), and the structured
        // full-fingerprint marker (the v3 dedup key — do not drop it).
        $this->pin($m[1], [
            '## Soft cabinet alert',
            '### Failures',
            SoftAlertWorkflowExpressions::FAILURES_JOIN_RAW,
            '<!-- soft-alert-fingerprint:${$(\'Webhook soft-alert\').first().json.body.fingerprint} -->',
            'https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md',
            'ssh root@193.232.229.92',
            "suggested?.diagnose || 'php artisan ops:soft-remediate --dry-run --json'",
            'H8562 — do not auto-apply hard fuse clears.',
        ]);

        $title = $this->expressions->evalAtom("$('Match exact title').first().json.expected_title", $context);
        $body = $this->expressions->evalTemplateLiteral($m[1], $context);

        return [(string) $title, $body];
    }

    /**
     * Comment bodies: `={{ JSON.stringify({ body: `…template…` }) }}` — the
     * template is evaluated, and its key fragments are pinned per node.
     *
     * @param  array<string, mixed>  $context
     */
    public function commentBody(string $name, string $rawJsonBody, array $context): string
    {
        if (preg_match('/^=\{\{ JSON\.stringify\(\{ body: `(.+)` \}\) \}\}$/s', trim($rawJsonBody), $m) !== 1) {
            throw new WorkflowGlueException("comment node '{$name}' jsonBody no longer matches the pinned template shape");
        }

        $template = $m[1];
        $pins = match ($name) {
            'GitHub comment on existing' => [
                'Same fingerprint re-fired — staying in this issue',
                '**When:**',
                SoftAlertWorkflowExpressions::FAILURES_JOIN_RAW,
            ],
            'GitHub comment duplicate note' => [
                'Closed as duplicate of #${$json.rival.number} — concurrent soft-alert deliveries converge on one issue (H8562).',
            ],
            'Sweep comment duplicate' => [
                'Closed as duplicate — periodic soft-alert dedup sweep (H8562). Survivor: the lowest-numbered OPEN issue with the same title and full fingerprint.',
            ],
            default => throw new WorkflowGlueException("no body pins for comment node '{$name}'"),
        };
        $this->pin($template, $pins);

        return $this->expressions->evalTemplateLiteral($template, $context);
    }

    /**
     * Code nodes: PHP mirrors behind strict grammar pins on the exported
     * jsCode. Any change to the exported logic fails loudly instead of
     * silently diverging from the replay.
     *
     * @param  list<array<string, mixed>>  $input
     * @return list<array<string, mixed>>
     */
    public function mirrorCodeNode(string $name, string $jsCode, array $input, DeliveryState $state): array
    {
        $items = array_map(static fn (array $i): array => $i['json'] ?? [], $input);

        if ($name === 'Match exact title') {
            return $this->mirrorMatchTitle($jsCode, $items, $state);
        }

        if ($name === 'Reconcile find rival') {
            return $this->mirrorReconcile($jsCode, $items, $state);
        }

        if ($name === 'Sweep group duplicates') {
            return $this->mirrorSweepGroup($jsCode, $items);
        }

        throw new WorkflowGlueException("code node '{$name}' has no replay mirror");
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mirrorMatchTitle(string $jsCode, array $items, DeliveryState $state): array
    {
        $this->pin($jsCode, [
            "const expected = '[soft-alert] ' + (body.scope || 'unknown') + ' ' + (body.fingerprint || '').slice(0, 8);",
            "const fp = String(body.fingerprint || '').trim().toLowerCase();",
            'soft-alert-fingerprint:([0-9a-fA-F]{8,128})',
            '\*\*Fingerprint:\*\*\s*`([0-9a-fA-F]{8,128})`',
            "filter(i => i && (i.title || '') === expected && i.state === 'open' && !i.pull_request && fp !== '' && fpOf(i.body) === fp)",
            'sort((a, b) => a.number - b.number)',
            'return [{ json: { expected_title: expected, matches } }];',
        ]);

        $body = $state->nodeOutputs['Webhook soft-alert'][0]['json']['body'] ?? [];
        $scope = (string) ($body['scope'] ?? '');
        $fingerprint = (string) ($body['fingerprint'] ?? '');
        $expected = '[soft-alert] '.($scope !== '' ? $scope : 'unknown').' '.substr($fingerprint, 0, 8);
        $fp = strtolower(trim($fingerprint));

        $matches = [];
        foreach ($items as $issue) {
            if ($fp === '' || ! $this->isExactOpenIssue($issue, $expected)) {
                continue;
            }
            if ($this->extractFingerprint($issue) === $fp) {
                $matches[] = ['number' => (int) $issue['number'], 'html_url' => (string) ($issue['html_url'] ?? '')];
            }
        }
        usort($matches, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return [['json' => ['expected_title' => $expected, 'matches' => $matches]]];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mirrorReconcile(string $jsCode, array $items, DeliveryState $state): array
    {
        $this->pin($jsCode, [
            "const expected = $('Match exact title').first().json.expected_title;",
            "const mine = $('GitHub create issue').first().json.number;",
            "const fp = String($('Webhook soft-alert').first().json.body.fingerprint || '').trim().toLowerCase();",
            'soft-alert-fingerprint:([0-9a-fA-F]{8,128})',
            '\*\*Fingerprint:\*\*\s*`([0-9a-fA-F]{8,128})`',
            "filter(i => i && (i.title || '') === expected && i.state === 'open' && !i.pull_request && i.number < mine && fp !== '' && fpOf(i.body) === fp)",
            'sort((a, b) => a.number - b.number)',
            'return [{ json: { mine, expected_title: expected, rival: rivals[0] || null, rivals_count: rivals.length } }];',
        ]);

        $expected = (string) $state->nodeOutputs['Match exact title'][0]['json']['expected_title'];
        $mine = (int) $state->nodeOutputs['GitHub create issue'][0]['json']['number'];
        $fp = strtolower(trim((string) ($state->nodeOutputs['Webhook soft-alert'][0]['json']['body']['fingerprint'] ?? '')));

        $rivals = [];
        foreach ($items as $issue) {
            if ($fp === '' || ! $this->isExactOpenIssue($issue, $expected) || (int) $issue['number'] >= $mine) {
                continue;
            }
            if ($this->extractFingerprint($issue) === $fp) {
                $rivals[] = $issue;
            }
        }
        usort($rivals, static fn (array $a, array $b): int => ((int) $a['number']) <=> ((int) $b['number']));

        return [[
            'json' => [
                'mine' => $mine,
                'expected_title' => $expected,
                'rival' => $rivals[0] ?? null,
                'rivals_count' => count($rivals),
            ],
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mirrorSweepGroup(string $jsCode, array $items): array
    {
        $this->pin($jsCode, [
            "if (!issue || issue.state !== 'open' || issue.pull_request) continue;",
            "if (!title.startsWith('[soft-alert] ')) continue;",
            'const fp = fpOf(issue.body);',
            'if (!fp) continue;',
            "const key = title + ' :: ' + fp;",
            'const group = groups[key].issues.sort((a, b) => a.number - b.number);',
            'for (const dup of group.slice(1)) {',
            'out.push({ json: { title: groups[key].title, survivor_number: group[0].number, duplicate_number: dup.number } });',
        ]);

        $groups = [];
        foreach ($items as $issue) {
            $title = (string) ($issue['title'] ?? '');
            if (($issue['state'] ?? '') !== 'open' || ! empty($issue['pull_request'] ?? null)) {
                continue;
            }
            if (! str_starts_with($title, '[soft-alert] ')) {
                continue;
            }
            $fp = $this->extractFingerprint($issue);
            if ($fp === null) {
                continue; // ambiguous legacy: never grouped, never closed
            }
            $key = $title.' :: '.$fp;
            $groups[$key]['title'] = $title;
            $groups[$key]['issues'][] = $issue;
        }

        $out = [];
        foreach ($groups as $group) {
            $issues = $group['issues'];
            usort($issues, static fn (array $a, array $b): int => ((int) $a['number']) <=> ((int) $b['number']));
            $survivor = (int) $issues[0]['number'];
            foreach (array_slice($issues, 1) as $dup) {
                $out[] = [
                    'json' => [
                        'title' => $group['title'],
                        'survivor_number' => $survivor,
                        'duplicate_number' => (int) $dup['number'],
                    ],
                ];
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $issue */
    private function isExactOpenIssue(array $issue, string $expectedTitle): bool
    {
        return ($issue['title'] ?? '') === $expectedTitle
            && ($issue['state'] ?? '') === 'open'
            && empty($issue['pull_request'] ?? null);
    }

    /**
     * Fail closed when the exported text no longer contains the pinned
     * fragments: the replay mirrors the export, it must never guess.
     *
     * @param  list<string>  $fragments
     */
    private function pin(string $code, array $fragments): void
    {
        foreach ($fragments as $fragment) {
            if (! str_contains($code, $fragment)) {
                throw new WorkflowGlueException(
                    "exported workflow text no longer matches the replay grammar.\n"
                    ."Missing fragment: {$fragment}\n"
                    .'Update the mirrors in tests/Support/N8n/ to follow the new export, '
                    .'or revert the export change — never loosen this pin to make a test pass.'
                );
            }
        }
    }
}
