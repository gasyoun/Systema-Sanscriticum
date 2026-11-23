<?php

declare(strict_types=1);

namespace Tests\Support\N8n;

/**
 * Mocked n8n runner for docs/n8n/soft-alert-to-github-issue.workflow.json (H8562 v3).
 *
 * The runner executes the REAL exported node graph: edges, URLs, query
 * parameters, expressions and Code-node sources are read from the export.
 * Code-node JavaScript and jsonBody templates are mirrored by
 * SoftAlertWorkflowPins behind strict grammar pins; expressions are
 * evaluated by SoftAlertWorkflowExpressions for the exact forms this export
 * uses; both fail closed on any drift. The GitHub side is MockGithub:
 * strongly consistent list, delayed search index — the consistency model
 * under which the reviewed search-based receiver was measured to diverge.
 * v3 transport semantics are replayed faithfully: alwaysOutputData (empty
 * result → one empty item; zero items in → the branch dies), and the
 * supported n8n pagination on every list node (walk all open pages, not
 * just the first 100).
 */
final class SoftAlertWorkflowReplay
{
    public const EXPORT = 'docs/n8n/soft-alert-to-github-issue.workflow.json';

    private const REQUIRED_NODES = [
        'Webhook soft-alert', 'IF secret matches', 'Respond 401',
        'GitHub list open issues', 'Match exact title', 'IF existing open issue',
        'GitHub comment on existing', 'Respond 200 deduped', 'GitHub create issue',
        'GitHub reconcile list', 'Reconcile find rival', 'IF we are the duplicate',
        'GitHub comment duplicate note', 'GitHub close duplicate',
        'Respond 202 converged', 'Respond 202',
        'Sweep schedule', 'Sweep list open issues', 'Sweep group duplicates',
        'Sweep close duplicate', 'Sweep comment duplicate',
    ];

    private MockGithub $github;

    private SoftAlertWorkflowExpressions $expressions;

    private SoftAlertWorkflowPins $pins;

    /** @var array<string, string> */
    private array $env;

    /** @var array<string, array<string, mixed>> node name => node definition */
    private array $nodes = [];

    /** @var array<string, mixed> */
    private array $connections = [];

    /**
     * @param  array<string, string>  $env  n8n env visible to expressions ($env.…)
     */
    public function __construct(MockGithub $github, array $env = [])
    {
        $this->github = $github;
        $this->env = $env;
        $this->expressions = new SoftAlertWorkflowExpressions;
        $this->pins = new SoftAlertWorkflowPins($this->expressions);

        $raw = (string) file_get_contents((string) base_path(self::EXPORT));
        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['nodes'], $data['connections'])) {
            throw new WorkflowGlueException('n8n export is not a workflow object');
        }
        foreach ($data['nodes'] as $node) {
            $this->nodes[(string) $node['name']] = $node;
        }
        $this->connections = $data['connections'];

        foreach (self::REQUIRED_NODES as $name) {
            if (! isset($this->nodes[$name])) {
                throw new WorkflowGlueException("export lost node '{$name}'; update the replay or revert the export");
            }
        }
    }

    /**
     * Expected issue title for a payload — the same construction the
     * "Match exact title" node performs (kept in sync by grammar pins).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function expectedTitle(array $payload): string
    {
        $scope = (string) ($payload['scope'] ?? '');
        $fingerprint = (string) ($payload['fingerprint'] ?? '');

        return '[soft-alert] '.($scope !== '' ? $scope : 'unknown').' '.substr($fingerprint, 0, 8);
    }

    /**
     * One webhook delivery. The returned state is driven phase by phase:
     * runLookup() → runCreate() → runReconcile().
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function startDelivery(array $payload, array $headers = []): DeliveryState
    {
        $state = new DeliveryState;
        $state->bind($this);
        $item = ['json' => ['body' => $payload, 'headers' => $headers]];
        $state->outputs['Webhook soft-alert'] = [$item];
        $state->nodeOutputs['Webhook soft-alert'] = [$item];

        $next = $this->nextNode('Webhook soft-alert', 0);
        if ($next === null) {
            throw new WorkflowGlueException('export lost the webhook connection');
        }
        $state->outputs[$next] = [$item];
        $state->pointer = $next;

        return $state;
    }

    /**
     * Phase L: secret gate + list + title match + existing-branch decision.
     * Stops right before "GitHub create issue" (deduped/401 deliveries
     * finish here instead).
     */
    public function runLookup(DeliveryState $state): void
    {
        $this->walk($state, ['GitHub create issue']);
    }

    /** Phase C: create the issue (and nothing else). */
    public function runCreate(DeliveryState $state): void
    {
        $this->walk($state, ['GitHub reconcile list']);
    }

    /** Phase R: reconcile list + duplicate decision + close/respond. */
    public function runReconcile(DeliveryState $state): void
    {
        $this->walk($state, []);
    }

    /** Drive one scheduled sweep pass (Sweep schedule → … → Sweep comment). */
    public function runSweep(): void
    {
        $state = new DeliveryState;
        $item = ['json' => []];
        $state->outputs['Sweep schedule'] = [$item];
        $state->nodeOutputs['Sweep schedule'] = [$item];

        $next = $this->nextNode('Sweep schedule', 0);
        if ($next === null) {
            throw new WorkflowGlueException('export lost the sweep connection');
        }
        $state->outputs[$next] = [$item];
        $state->pointer = $next;

        $this->walk($state, []);
    }

    // ------------------------------------------------------------------ Walk

    /**
     * @param  list<string>  $stopBefore  node names to stop in front of
     */
    private function walk(DeliveryState $state, array $stopBefore): void
    {
        while ($state->pointer !== null && ! $state->finished) {
            $name = (string) $state->pointer;
            if (in_array($name, $stopBefore, true)) {
                return;
            }

            $input = $state->outputs[$name] ?? null;
            if ($input === null) {
                throw new WorkflowGlueException("no input wired into '{$name}'");
            }

            if ($input === []) {
                // n8n transport semantics: a node that receives ZERO items
                // never executes — the branch dies silently here. This is the
                // exact behavior that made the pre-v3 export lose the FIRST
                // issue on a genuinely empty board (independent review 23-11):
                // the list node returned no items, "Match exact title" never
                // ran, create never fired. The export now carries
                // alwaysOutputData on every list node so this branch only
                // dies when the export really lost the flag.
                $state->finished = true;
                $state->pointer = null;

                return;
            }

            $this->executeNode($state, $name, $this->nodes[$name], $input);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $input
     */
    private function executeNode(DeliveryState $state, string $name, array $node, array $input): void
    {
        $type = (string) $node['type'];

        if ($type === 'n8n-nodes-base.if') {
            $branch = $this->evaluateIf($node, $this->singleJson($input, $name), $state);
            $this->advance($state, $name, $branch, $input);

            return;
        }

        if ($type === 'n8n-nodes-base.code') {
            $items = $this->pins->mirrorCodeNode($name, (string) ($node['parameters']['jsCode'] ?? ''), $input, $state);
            $this->advance($state, $name, 0, $items);

            return;
        }

        if ($type === 'n8n-nodes-base.httpRequest') {
            $items = $this->executeHttpNode($name, $node, $input, $state);
            $this->advance($state, $name, 0, $items);

            return;
        }

        if ($type === 'n8n-nodes-base.respondToWebhook') {
            $code = (int) ($node['parameters']['options']['responseCode'] ?? 200);
            $body = $this->expressions->evalExpr(
                (string) ($node['parameters']['responseBody'] ?? ''),
                $this->context($state, $this->singleJson($input, $name))
            );
            $state->responses[] = ['code' => $code, 'body' => is_array($body) ? $body : ['value' => $body]];
            $state->finished = true;
            $state->pointer = null;

            return;
        }

        if ($type === 'n8n-nodes-base.webhook' || $type === 'n8n-nodes-base.scheduleTrigger') {
            $this->advance($state, $name, 0, $input);

            return;
        }

        throw new WorkflowGlueException("replay cannot execute node type '{$type}' ('{$name}')");
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function advance(DeliveryState $state, string $from, int $branch, array $items): void
    {
        $state->nodeOutputs[$from] = $items;

        $next = $this->nextNode($from, $branch);
        if ($next === null) {
            $state->finished = true;
            $state->pointer = null;

            return;
        }
        $state->outputs[$next] = $items;
        $state->pointer = $next;
    }

    private function nextNode(string $from, int $branch): ?string
    {
        $conns = $this->connections[$from]['main'][$branch][0]['node'] ?? null;

        return is_string($conns) ? $conns : null;
    }

    // -------------------------------------------------------------------- HTTP

    /**
     * HTTP Request node. n8n runs one request per input item; an array
     * response becomes one output item per element. The GET-list branch
     * mirrors the export's SUPPORTED pagination (Update a Parameter in Each
     * Request: qs.page = $pageCount + 1, Pagination Complete When: Response
     * Is Empty) and the alwaysOutputData node setting: with the flag an
     * empty result still emits ONE empty item, without it zero items flow
     * onward and the branch dies (the pre-v3 first-issue bug).
     *
     * @param  list<array<string, mixed>>  $input
     * @return list<array<string, mixed>>
     */
    private function executeHttpNode(string $name, array $node, array $input, DeliveryState $state): array
    {
        $params = $node['parameters'];
        $method = strtoupper((string) ($params['method'] ?? 'GET'));
        $out = [];

        foreach ($input as $item) {
            $json = $item['json'] ?? [];
            $context = $this->context($state, $json);
            $url = (string) $this->expressions->evalExpr((string) ($params['url'] ?? ''), $context);

            if ($method === 'GET') {
                $query = [];
                foreach ((array) ($params['queryParameters']['parameters'] ?? []) as $p) {
                    $query[(string) ($p['name'] ?? '')] = (string) $this->expressions->evalExpr((string) ($p['value'] ?? ''), $context);
                }

                if (str_contains($url, '/search/issues')) {
                    $response = $this->github->searchIssues((string) ($query['q'] ?? ''));
                    $out[] = ['json' => $response];
                } else {
                    foreach ($this->listWithPagination($node, $query) as $element) {
                        $out[] = ['json' => $element];
                    }
                }
            } else {
                $response = $this->executeWriteNode($name, $url, (string) ($params['jsonBody'] ?? ''), $context, $state);
                if (is_array($response) && array_is_list($response)) {
                    foreach ($response as $element) {
                        $out[] = ['json' => $element];
                    }
                } else {
                    $out[] = ['json' => $response];
                }
            }
        }

        // n8n node setting (Settings tab): Always Output Data — an empty
        // result still emits one empty item instead of none.
        if ($out === [] && (bool) ($node['alwaysOutputData'] ?? false)) {
            $out = [['json' => []]];
        }

        return $out;
    }

    /**
     * The export's supported n8n pagination, replayed: "Update a Parameter
     * in Each Request" (Query page = $pageCount + 1) with "Pagination
     * Complete When: Response Is Empty". n8n continues while the last
     * response body is a non-empty array, so the replay keeps requesting
     * page N+1 until a page comes back empty — bounded by Max Pages when
     * Limit Pages Fetched is set. Anything else runs the single-request
     * legacy path.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function listWithPagination(array $node, array $query): array
    {
        $pagination = $node['parameters']['options']['pagination']['pagination'] ?? null;
        $mode = is_array($pagination) ? (string) ($pagination['paginationMode'] ?? 'off') : 'off';

        if ($mode !== 'updateAParameterInEachRequest') {
            return $this->github->listIssues($query);
        }

        $maxRequests = PHP_INT_MAX;
        if ((bool) ($pagination['limitPagesFetched'] ?? false)) {
            $maxRequests = max(1, (int) ($pagination['maxRequests'] ?? 1));
        }

        $items = [];
        $page = 1;
        while (true) {
            // The first request carries the node's own query (GitHub page 1);
            // every following request updates qs.page ($pageCount + 1).
            $pageQuery = $page === 1 ? $query : $query + ['page' => (string) $page];
            $response = $this->github->listIssues($pageQuery);
            foreach ($response as $element) {
                $items[] = $element;
            }

            if ($response === [] || $page >= $maxRequests) {
                return $items;
            }

            $page++;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function executeWriteNode(string $name, string $url, string $jsonBody, array $context, DeliveryState $state): array
    {
        if ($name === 'GitHub create issue') {
            [$title, $body] = $this->pins->parseCreateIssue($jsonBody, $context);
            $issue = $this->github->createIssue($title, $body, ['ops-soft-alert']);
            $state->createdNumber = (int) $issue['number'];

            return $issue;
        }

        $number = $this->urlIssueNumber($url, $name);

        if ($name === 'GitHub close duplicate' || $name === 'Sweep close duplicate') {
            if (! str_contains($jsonBody, "JSON.stringify({ state: 'closed', state_reason: 'completed' })")) {
                throw new WorkflowGlueException("close node '{$name}' jsonBody no longer matches the pinned close shape");
            }

            return $this->github->closeIssue($number);
        }

        if (in_array($name, ['GitHub comment on existing', 'GitHub comment duplicate note', 'Sweep comment duplicate'], true)) {
            return $this->github->addComment($number, $this->pins->commentBody($name, $jsonBody, $context));
        }

        throw new WorkflowGlueException("no replay mirror for write node '{$name}'");
    }

    private function urlIssueNumber(string $url, string $name): int
    {
        if (preg_match('~/issues/(\d+)~', $url, $m) !== 1) {
            throw new WorkflowGlueException("node '{$name}' URL has no issue number: {$url}");
        }

        return (int) $m[1];
    }

    // ----------------------------------------------------------- IF conditions

    /**
     * @param  array<string, mixed>  $json
     */
    private function evaluateIf(array $node, array $json, DeliveryState $state): int
    {
        $context = $this->context($state, $json);
        $conditions = (array) ($node['parameters']['conditions'] ?? []);

        foreach ($conditions as $type => $list) {
            foreach ((array) $list as $condition) {
                $v1 = $this->expressions->evalExpr((string) ($condition['value1'] ?? ''), $context);
                $v2 = $this->conditionValue2($condition, $type, $context);

                $ok = match ($type) {
                    'string' => (string) $v1 === (string) $v2,
                    'number' => $this->compareNumbers((string) ($condition['operation'] ?? 'equals'), $v1, $v2),
                    'boolean' => ((bool) $v1) === ((bool) $v2),
                    default => throw new WorkflowGlueException("IF condition type '{$type}' has no replay mirror"),
                };

                if (! $ok) {
                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $context
     */
    private function conditionValue2(array $condition, string $type, array $context): mixed
    {
        if (! array_key_exists('value2', $condition)) {
            if ($type === 'boolean') {
                return true; // n8n IF v1 boolean condition defaults to true
            }

            return null;
        }

        $raw = $condition['value2'];

        return is_string($raw) ? $this->expressions->evalExpr($raw, $context) : $raw;
    }

    private function compareNumbers(string $operation, mixed $v1, mixed $v2): bool
    {
        return match ($operation) {
            'larger' => (float) $v1 > (float) $v2,
            default => (float) $v1 === (float) $v2,
        };
    }

    // ----------------------------------------------------------------- Helpers

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function singleJson(array $items, string $name): array
    {
        if (count($items) !== 1) {
            throw new WorkflowGlueException("node '{$name}' received ".count($items).' items; this replay expects exactly 1');
        }

        return $items[0]['json'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function context(DeliveryState $state, array $json): array
    {
        return ['json' => $json, 'outputs' => $state->nodeOutputs, 'env' => $this->env];
    }
}
