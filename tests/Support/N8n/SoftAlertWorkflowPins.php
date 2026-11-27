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
            .' body: \$\(\'Match exact title\'\)\.first\(\)\.json\.issue_body, labels: \[\'ops-soft-alert\'\], assignees: \[\'pe4kinsmart-tech\'\] \}\) \}\}$/';

        if (preg_match($pattern, trim($rawJsonBody), $m) !== 1) {
            throw new WorkflowGlueException(
                'GitHub create issue jsonBody no longer matches the pinned H8562 shape '
                .'(flat expression: title + issue_body both from Match exact title, labels/assignees contract). '
                .'Update tests/Support/N8n/ mirrors or revert the export.'
            );
        }

        // Failed-unit detection must survive every export edit: the issue
        // body keeps the failures list, playbook link, the suggested
        // diagnose command (H8562 scope guard) and the structured
        // full-fingerprint marker (the v3 dedup key - do not drop it).
        // Since the live-fix (27-11-2026, execution 17389: n8n extendSyntax
        // rejects template literals inside ={{ }} jsonBody) the body is
        // composed in the Match Code node - the behavioral pins live in
        // mirrorMatchTitle(), and ReviewReplayTest asserts the marker in the
        // created issue end-to-end.
        $title = (string) $this->expressions->evalAtom("$('Match exact title').first().json.expected_title", $context);
        $body = (string) $this->expressions->evalAtom("$('Match exact title').first().json.issue_body", $context);

        return [$title, $body];
    }

    /**
     * Comment bodies: `={{ JSON.stringify({ body: `…template…` }) }}` — the
     * template is evaluated, and its key fragments are pinned per node.
     *
     * @param  array<string, mixed>  $context
     */
    public function commentBody(string $name, string $rawJsonBody, array $context): string
    {
        // live-fix 27-11-2026: static literal JSON body (no expression at all).
        if ($name === 'Sweep comment duplicate') {
            $decoded = json_decode(trim($rawJsonBody), true);
            if (! is_array($decoded) || ! isset($decoded['body']) || ! is_string($decoded['body'])) {
                throw new WorkflowGlueException("comment node '{$name}' jsonBody no longer matches the pinned static shape");
            }
            $this->pin($decoded['body'], [
                'Closed as duplicate - periodic soft-alert dedup sweep (H8562). Survivor: the lowest-numbered OPEN issue with the same title and full fingerprint.',
            ]);

            return $decoded['body'];
        }

        if ($name === 'GitHub comment on existing') {
            if (preg_match('/^=\{\{ JSON\.stringify\(\{ body: \$\(\'Match exact title\'\)\.first\(\)\.json\.repeat_comment_body \}\) \}\}$/', trim($rawJsonBody)) !== 1) {
                throw new WorkflowGlueException("comment node '{$name}' jsonBody no longer matches the pinned composer-reference shape");
            }
            $body = (string) $this->expressions->evalAtom("$('Match exact title').first().json.repeat_comment_body", $context);
            $this->pin($body, [
                'Same fingerprint re-fired - staying in this issue',
                '**When:**',
                '**Failures:**',
            ]);

            return $body;
        }

        if ($name === 'GitHub comment duplicate note') {
            if (preg_match('/^=\{\{ JSON\.stringify\(\{ body: \$json\.dup_note_body \}\) \}\}$/', trim($rawJsonBody)) !== 1) {
                throw new WorkflowGlueException("comment node '{$name}' jsonBody no longer matches the pinned composer-reference shape");
            }
            $body = (string) ($context['json']['dup_note_body'] ?? '');
            $this->pin($body, [
                'Closed as duplicate of #',
                '- concurrent soft-alert deliveries converge on one issue (H8562).',
            ]);

            return $body;
        }

        throw new WorkflowGlueException("no body pins for comment node '{$name}'");
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
            "const failuresList = (body.failures || []).map(f => '- ' + (f.message || '')).join('\\n');",
            "'<!-- soft-alert-fingerprint:' + (body.fingerprint || '') + ' -->',",
            "'Same fingerprint re-fired - staying in this issue (H8562 dedup; sender flap grace landed in Systema PR).',",
            'return [{ json: { expected_title: expected, matches, issue_body, repeat_comment_body } }];',
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

        // Mirror the body composer byte-for-byte with the exported JS
        // (live-fix 27-11-2026): failures list, playbook, suggested fence,
        // assignee/label contract line, structured marker, repeat comment.
        $failuresList = implode("\n", array_map(
            static fn (array $f): string => '- '.(string) ($f['message'] ?? ''),
            $body['failures'] ?? [],
        ));
        $suggested = (string) ($body['suggested']['diagnose'] ?? '');
        $occurred = (string) ($body['occurred_at'] ?? '');
        $issueBody = implode("\n", [
            '## Soft cabinet alert',
            '',
            '**Scope:** '.$scope,
            '**Fingerprint:** `'.$fingerprint.'`',
            '<!-- soft-alert-fingerprint:'.$fingerprint.' -->',
            '**When:** '.$occurred,
            '',
            '### Failures',
            $failuresList,
            '',
            '### Playbook',
            'https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md',
            '',
            '### Suggested (agent)',
            '```',
            'ssh root@193.232.229.92',
            'cd /var/www/html',
            $suggested !== '' ? $suggested : 'php artisan ops:soft-remediate --dry-run --json',
            '```',
            '',
            'Assignee: @pe4kinsmart-tech',
            'Label: ops-soft-alert',
            'H8562 - do not auto-apply hard fuse clears.',
        ]);
        $repeatCommentBody = implode("\n", [
            'Same fingerprint re-fired - staying in this issue (H8562 dedup; sender flap grace landed in Systema PR).',
            '',
            '**When:** '.$occurred,
            '**Failures:**',
            $failuresList,
        ]);

        return [['json' => [
            'expected_title' => $expected,
            'matches' => $matches,
            'issue_body' => $issueBody,
            'repeat_comment_body' => $repeatCommentBody,
        ]]];
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
            "const dup_note_body = rivals[0] ? ('Closed as duplicate of #' + rivals[0].number + ' - concurrent soft-alert deliveries converge on one issue (H8562).') : '';",
            'return [{ json: { mine, expected_title: expected, rival: rivals[0] || null, rivals_count: rivals.length, dup_note_body } }];',
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

        $dupNoteBody = isset($rivals[0])
            ? 'Closed as duplicate of #'.((int) $rivals[0]['number']).' - concurrent soft-alert deliveries converge on one issue (H8562).'
            : '';

        return [[
            'json' => [
                'mine' => $mine,
                'expected_title' => $expected,
                'rival' => $rivals[0] ?? null,
                'rivals_count' => count($rivals),
                'dup_note_body' => $dupNoteBody,
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
            "out.push({ json: { title: groups[key].title, survivor_number: group[0].number, duplicate_number: dup.number, sweep_note_body: 'Closed as duplicate - periodic soft-alert dedup sweep (H8562). Survivor: the lowest-numbered OPEN issue with the same title and full fingerprint.' } });",
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
                        'sweep_note_body' => 'Closed as duplicate - periodic soft-alert dedup sweep (H8562). Survivor: the lowest-numbered OPEN issue with the same title and full fingerprint.',
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
