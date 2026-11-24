<?php

declare(strict_types=1);

namespace Tests\Support\N8n;

use RuntimeException;

/**
 * In-memory GitHub mock for the H8562 soft-alert receiver replay.
 *
 * Models exactly the consistency gap that failed the independent review:
 * the REST issue list is strongly consistent (a create is visible to the
 * next list call), while the search index is DELAYED — an issue becomes
 * searchable only after flushSearchIndex(), which the replays never call.
 * Under that model a search-based receiver (the reviewed FAIL) diverges,
 * and any receiver that reads only the list converges.
 */
final class MockGithub
{
    private const REPO = 'gasyoun/Systema-Sanscriticum';

    /** @var array<int, array<string, mixed>> issue number => issue data */
    private array $issues = [];

    /** @var array<int, list<string>> issue number => comment bodies in order */
    private array $comments = [];

    /** @var list<array{method: string, url: string}> */
    private array $calls = [];

    private int $nextNumber = 5001;

    /** @var array<int, bool> issue numbers visible to the delayed search index */
    private array $searchIndexed = [];

    /**
     * Simulate the search index catching up. The concurrency replays never
     * call this: during a delivery the index is as stale as the review
     * measured it to be.
     */
    public function flushSearchIndex(): void
    {
        foreach (array_keys($this->issues) as $number) {
            $this->searchIndexed[$number] = true;
        }
    }

    /**
     * GET /repos/{owner}/{repo}/issues — strongly consistent list, paged the
     * way GitHub pages it (page 1 default; per_page slice per page).
     *
     * @param  array<string, string>  $query  state / labels / sort / direction / per_page / page
     * @return list<array<string, mixed>>
     */
    public function listIssues(array $query): array
    {
        $this->calls[] = ['method' => 'GET', 'url' => '/'.self::REPO.'/issues?'.http_build_query($query)];

        $state = (string) ($query['state'] ?? 'open');
        $label = (string) ($query['labels'] ?? 'ops-soft-alert');

        $items = array_values(array_filter(
            $this->issues,
            static fn (array $i): bool => ($i['state'] ?? '') === $state
                && in_array($label, (array) ($i['labels'] ?? []), true)
        ));

        // sort=created&direction=desc: numbers are assigned serially at
        // create time, so number-desc mirrors created-desc.
        usort($items, static fn (array $a, array $b): int => ($b['number'] ?? 0) <=> ($a['number'] ?? 0));

        $perPage = max(1, (int) ($query['per_page'] ?? 100));
        $page = max(1, (int) ($query['page'] ?? 1));

        return array_slice($items, ($page - 1) * $perPage, $perPage);
    }

    /**
     * GET /search/issues — the DELAYED index. Only flushed issues are
     * visible, so within a delivery window the index is empty or stale.
     *
     * @return array{total_count: int, items: list<array<string, mixed>>}
     */
    public function searchIssues(string $q): array
    {
        $this->calls[] = ['method' => 'GET', 'url' => '/search/issues?q='.rawurlencode($q)];

        $phrase = '';
        if (preg_match('/"(.+)"/', $q, $m) === 1) {
            $phrase = $m[1];
        }

        $items = array_values(array_filter(
            $this->issues,
            fn (array $i): bool => ($i['title'] ?? '') === $phrase
                && ($i['state'] ?? '') === 'open'
                && ! isset($i['pull_request'])
                && ! empty($this->searchIndexed[$i['number']] ?? false)
        ));

        return ['total_count' => count($items), 'items' => array_slice($items, 0, 30)];
    }

    /**
     * POST /repos/{owner}/{repo}/issues — visible to the next list call.
     *
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    public function createIssue(string $title, string $body, array $labels): array
    {
        $this->calls[] = ['method' => 'POST', 'url' => '/'.self::REPO.'/issues'];

        $number = $this->nextNumber++;
        $issue = [
            'number' => $number,
            'title' => $title,
            'body' => $body,
            'state' => 'open',
            'labels' => array_values($labels),
            'html_url' => 'https://github.com/'.self::REPO."/issues/{$number}",
        ];
        $this->issues[$number] = $issue;

        return $issue;
    }

    /** @return array<string, mixed> */
    public function addComment(int $number, string $body): array
    {
        $this->calls[] = ['method' => 'POST', 'url' => '/'.self::REPO."/issues/{$number}/comments"];
        $this->assertIssueExists($number);
        $this->comments[$number][] = $body;

        return ['id' => random_int(1, PHP_INT_MAX), 'body' => $body];
    }

    /** @return array<string, mixed> */
    public function closeIssue(int $number): array
    {
        $this->calls[] = ['method' => 'PATCH', 'url' => '/'.self::REPO."/issues/{$number}"];
        $this->assertIssueExists($number);
        $this->issues[$number]['state'] = 'closed';
        $this->issues[$number]['state_reason'] = 'completed';

        return $this->issues[$number];
    }

    // ---------------------------------------------------------------- Seeding

    /**
     * Seed a pre-existing issue (backlog storm, noise, label-less foreign
     * issues). Seeded issues get lower numbers than later webhook creates.
     *
     * @param  array<string, mixed>  $overrides  labels / state / body
     * @return array<string, mixed>
     */
    public function seedIssue(string $title, array $overrides = []): array
    {
        $number = $this->nextNumber++;
        $issue = array_merge([
            'number' => $number,
            'title' => $title,
            'body' => 'seeded body #'.$number,
            'state' => 'open',
            'labels' => ['ops-soft-alert'],
            'html_url' => 'https://github.com/'.self::REPO."/issues/{$number}",
        ], $overrides);
        $issue['number'] = $number;
        $this->issues[$number] = $issue;

        return $issue;
    }

    /**
     * Seed a LEGACY soft-alert issue the way pre-v3 receivers wrote it: the
     * full fingerprint sits in the body as the **Fingerprint:** markdown
     * line (no structured marker). The v3 receiver must still prove the
     * fingerprint through that line — this seed models the 21–23.11 storm
     * backlog and the August issues.
     *
     * @param  array<string, mixed>  $overrides  labels / state
     * @return array<string, mixed>
     */
    public function seedLegacyIssue(string $title, string $fingerprint, array $overrides = []): array
    {
        $body = "## Soft cabinet alert\n\n**Fingerprint:** `{$fingerprint}`\n";

        return $this->seedIssue($title, $overrides + ['body' => $body]);
    }

    /**
     * Seed a pull request sharing the exact soft-alert title. The GitHub
     * list endpoint returns PRs mixed with issues — the export must filter
     * them out (`!i.pull_request`).
     *
     * @return array<string, mixed>
     */
    public function seedPullRequest(string $title): array
    {
        $pr = $this->seedIssue($title);
        $this->issues[$pr['number']]['pull_request'] = ['url' => 'https://api.github.com/repos/'.self::REPO.'/pulls/'.$pr['number']];

        return $this->issues[$pr['number']];
    }

    // ------------------------------------------------------------ Assertions

    /**
     * @return list<int> open ISSUE numbers with an exact title, ascending.
     *                   PRs sharing the title are not issues — the receiver
     *                   dedup must never touch them.
     */
    public function openNumbersByTitle(string $title): array
    {
        $numbers = [];
        foreach ($this->issues as $i) {
            if (($i['title'] ?? '') === $title && ($i['state'] ?? '') === 'open' && ! isset($i['pull_request'])) {
                $numbers[] = (int) $i['number'];
            }
        }
        sort($numbers);

        return $numbers;
    }

    /**
     * @return list<int> closed ISSUE numbers with an exact title, ascending
     */
    public function closedNumbersByTitle(string $title): array
    {
        $numbers = [];
        foreach ($this->issues as $i) {
            if (($i['title'] ?? '') === $title && ($i['state'] ?? '') === 'closed' && ! isset($i['pull_request'])) {
                $numbers[] = (int) $i['number'];
            }
        }
        sort($numbers);

        return $numbers;
    }

    /** @return list<string> */
    public function commentsOn(int $number): array
    {
        return $this->comments[$number] ?? [];
    }

    /** @return array<string, mixed> */
    public function issue(int $number): array
    {
        return $this->issues[$number] ?? [];
    }

    /** Total number of mock GitHub API calls made by the replayed flows. */
    public function githubCallCount(): int
    {
        return count($this->calls);
    }

    /**
     * Calls that touched the delayed search index. The H8562 v2 receiver
     * must make zero: its dedup reads only the consistent list.
     */
    public function searchCallCount(): int
    {
        return count(array_filter(
            $this->calls,
            static fn (array $c): bool => str_contains((string) $c['url'], '/search/issues')
        ));
    }

    /** @return list<array{method: string, url: string}> */
    public function calls(): array
    {
        return $this->calls;
    }

    private function assertIssueExists(int $number): void
    {
        if (! isset($this->issues[$number])) {
            throw new RuntimeException("mock GitHub: issue #{$number} does not exist");
        }
    }
}
