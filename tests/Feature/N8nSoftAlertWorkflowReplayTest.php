<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\N8n\MockGithub;
use Tests\Support\N8n\SoftAlertWorkflowReplay;
use Tests\TestCase;

/**
 * H8562 v2 — mocked runner replay поверх MockGithub (лист строго консистентен,
 * индекс поиска лагает): конкурентные доставки одного отпечатка сходятся в
 * один issue, новый отпечаток открывает свой, sweep гасит бэклог. Находки
 * вечернего review — в N8nSoftAlertWorkflowReviewReplayTest; rationale:
 * docs/ops/SOFT_ALERT_WEBHOOK.md §4.
 */
class N8nSoftAlertWorkflowReplayTest extends TestCase
{
    private const SECRET = 'test-soft-alert-secret';

    /** Storm fingerprint: sha256("guards/failed-units") starts 6fd69840. */
    private const FP_X = '6fd6984000000000000000000000000000000000000000000000000000000000000';

    private const FP_Y = '91ab2c3d0000000000000000000000000000000000000000000000000000000000';

    /**
     * Ядро приемки: обе доставки смотрят список ДО любых create (худший
     * случай review), затем create/reconcile идут во ВСЕХ шести валидных
     * порядках. Каждый обязан сходиться в один открытый issue: закрывается
     * ровно старший номер, с комментарием-ссылкой на выжившего, без
     * обращений к поисковому индексу.
     */
    public function test_concurrent_deliveries_converge_in_every_interleaving_under_delayed_search_visibility(): void
    {
        $title = $this->titleX();
        $orderIndex = 0;

        foreach ($this->interleavings() as $order) {
            $orderIndex++;
            $mock = new MockGithub;
            $replay = new SoftAlertWorkflowReplay($mock, $this->env());

            $a = $replay->startDelivery($this->payloadX('2026-11-23T18:00:00+00:00'), $this->headers());
            $b = $replay->startDelivery($this->payloadX('2026-11-23T18:00:01+00:00'), $this->headers());

            // Worst case from the review: both lookups run before any create.
            $a->runLookup();
            $b->runLookup();
            $this->assertFalse($a->finished(), "order #{$orderIndex}: A must reach create");
            $this->assertFalse($b->finished(), "order #{$orderIndex}: B must reach create");

            $deliveries = ['A' => $a, 'B' => $b];
            foreach ($order as $phase) {
                $delivery = $deliveries[substr((string) $phase, 1, 1)];
                substr((string) $phase, 0, 1) === 'C' ? $delivery->runCreate() : $delivery->runReconcile();
            }

            // Exactly one open issue with the exact title.
            $open = $mock->openNumbersByTitle($title);
            $this->assertCount(1, $open, "order #{$orderIndex} (".implode(',', $order).'): one issue must stay open');

            // The duplicate (higher number) closed itself, naming the survivor.
            $survivor = min($a->createdNumber(), $b->createdNumber());
            $duplicate = max($a->createdNumber(), $b->createdNumber());
            $this->assertSame($survivor, $open[0], "order #{$orderIndex}: lowest number survives");
            $this->assertSame([$duplicate], $mock->closedNumbersByTitle($title), "order #{$orderIndex}: exactly the duplicate closed");
            $this->assertCount(1, $mock->commentsOn($duplicate), "order #{$orderIndex}: duplicate-note comment");
            $this->assertStringContainsString("duplicate of #{$survivor}", $mock->commentsOn($duplicate)[0]);
            $this->assertSame([], $mock->commentsOn($survivor), "order #{$orderIndex}: survivor untouched");

            // The design never touches the lagged search index.
            $this->assertSame(0, $mock->searchCallCount(), "order #{$orderIndex}: receiver must be search-free");

            // Both deliveries answered, exactly one converged.
            $codes = array_map(static fn (array $r): int => $r['code'], array_merge($a->responses(), $b->responses()));
            $this->assertEqualsCanonicalizing([202, 202], $codes, "order #{$orderIndex}: both deliveries answered 202");
            $bodies = array_merge($a->responses(), $b->responses());
            $converged = array_filter($bodies, static fn (array $r): bool => ! empty($r['body']['converged']));
            $this->assertCount(1, $converged, "order #{$orderIndex}: exactly one delivery converged");
        }

        $this->assertSame(6, $orderIndex, 'all create/reconcile interleavings must be exercised');
    }

    /**
     * FAIL-repro из independent review (документация, не цель): под
     * задержанным индексом search-then-create + немедленный search-reconcile
     * расходятся — обе доставки видят 0, обе создают, оба reconcile видят
     * пустоту, никто не закрывается. Ровно поэтому экспорт обязан жить без
     * /search/issues. Индекс в моке ДОГОНЯЕТСЯ flushSearchIndex() — лаг
     * моделирует задержку, а не отсутствие данных.
     */
    public function test_delayed_search_index_reproduces_the_reviewed_fail_in_a_search_based_receiver(): void
    {
        $mock = new MockGithub;
        $title = $this->titleX();
        $q = 'repo:gasyoun/Systema-Sanscriticum is:issue is:open in:title "'.$title.'"';

        // Both immediate lookups miss (delayed index).
        $this->assertSame(0, $mock->searchIssues($q)['total_count']);
        $this->assertSame(0, $mock->searchIssues($q)['total_count']);

        // Both create.
        $issueA = $mock->createIssue($title, 'a', ['ops-soft-alert']);
        $issueB = $mock->createIssue($title, 'b', ['ops-soft-alert']);

        // Both immediate search reconciles see nothing → old predicate
        // (close self when search finds a smaller-number open issue) never fires.
        foreach ([$issueA, $issueB] as $issue) {
            $found = array_filter(
                $mock->searchIssues($q)['items'],
                static fn (array $i): bool => $i['number'] < $issue['number']
            );
            if ($found !== []) {
                $mock->closeIssue((int) $issue['number']);
            }
        }

        $this->assertCount(2, $mock->openNumbersByTitle($title), 'reviewed FAIL: two duplicates stay open');

        // The lag was delay, not absence: after the index catches up a late
        // search sees both — a durable (delayed) reconcile could fix the
        // backlog, the immediate-only path could not.
        $mock->flushSearchIndex();
        $this->assertSame(2, $mock->searchIssues($q)['total_count']);
    }

    /**
     * Сценарий «separate new fingerprint» из PR #4481: после сходимости X
     * новый отпечаток Y открывает СВОЙ issue (различение новых классов
     * сохранено), failed-units попадают в тело issue, повторная доставка X
     * дописывается комментарием в выжившего, sweep после сходимости молчит.
     */
    public function test_separate_new_fingerprint_gets_its_own_issue_and_failed_units_land(): void
    {
        $mock = new MockGithub;
        $replay = new SoftAlertWorkflowReplay($mock, $this->env());

        // Converge X first (one plain interleaving).
        $a = $replay->startDelivery($this->payloadX('2026-11-23T18:00:00+00:00'), $this->headers());
        $b = $replay->startDelivery($this->payloadX('2026-11-23T18:00:01+00:00'), $this->headers());
        $a->runLookup();
        $b->runLookup();
        $a->runCreate();
        $b->runCreate();
        $a->runReconcile();
        $b->runReconcile();
        $survivorX = min($a->createdNumber(), $b->createdNumber());
        $this->assertCount(1, $mock->openNumbersByTitle($this->titleX()));

        // New fingerprint Y: a genuinely new failure class.
        $y = $replay->startDelivery(
            $this->payload(self::FP_Y, '2026-11-23T19:00:00+00:00', 'guards/tunnel-probe: socks down'),
            $this->headers()
        );
        $y->runLookup();
        $this->assertFalse($y->finished(), 'new fingerprint must not dedup into X');
        $y->runCreate();
        $y->runReconcile();
        $this->assertTrue($y->finished());

        $titleY = SoftAlertWorkflowReplay::expectedTitle($this->payload(self::FP_Y, 'irrelevant', 'irrelevant'));
        $this->assertNotSame($this->titleX(), $titleY);
        $this->assertCount(1, $mock->openNumbersByTitle($titleY), 'Y gets its own open issue');

        $yNumber = $y->createdNumber();
        $this->assertNotNull($yNumber);
        $yIssue = $mock->issue($yNumber);
        $this->assertSame($titleY, $yIssue['title']);

        // Failed-unit detection preserved: Y's failure, playbook, ssh line.
        $this->assertStringContainsString('guards/tunnel-probe: socks down', (string) $yIssue['body']);
        $this->assertStringContainsString('SERVER_SOFT_ALERT_PLAYBOOK.md', (string) $yIssue['body']);
        $this->assertStringContainsString('ssh root@193.232.229.92', (string) $yIssue['body']);

        // X untouched by Y's delivery: still exactly one open.
        $this->assertCount(1, $mock->openNumbersByTitle($this->titleX()));

        // Redelivery of X comments on the survivor, opens nothing.
        $xAgain = $replay->startDelivery(
            $this->payload(self::FP_X, '2026-11-23T19:30:00+00:00', 'guards/failed-units: telegram-daily.service'),
            $this->headers()
        );
        $xAgain->runLookup();
        $this->assertTrue($xAgain->finished(), 'redelivery must finish in the lookup phase');
        $this->assertNull($xAgain->createdNumber(), 'redelivery must not create');
        $this->assertCount(1, $mock->openNumbersByTitle($this->titleX()));

        $response = $xAgain->responses()[0];
        $this->assertSame(200, $response['code']);
        $this->assertTrue((bool) $response['body']['deduped']);

        $survivorComments = $mock->commentsOn($survivorX);
        $this->assertCount(1, $survivorComments);
        $this->assertStringContainsString('Same fingerprint re-fired', $survivorComments[0]);
        $this->assertStringContainsString('guards/failed-units: telegram-daily.service', $survivorComments[0]);
        $this->assertStringContainsString('2026-11-23T19:30:00+00:00', $survivorComments[0]);

        // Sweep after convergence is a no-op.
        $replay->runSweep();
        $this->assertCount(1, $mock->openNumbersByTitle($this->titleX()));
        $this->assertCount(1, $mock->openNumbersByTitle($titleY));
        $this->assertSame([], $mock->closedNumbersByTitle($titleY));
    }

    /**
     * Секретный гейт: неверный секрет → 401, ноль обращений к GitHub.
     */
    public function test_secret_gate_rejects_delivery_without_github_calls(): void
    {
        $mock = new MockGithub;
        $replay = new SoftAlertWorkflowReplay($mock, $this->env());

        $delivery = $replay->startDelivery(
            $this->payloadX('2026-11-23T18:00:00+00:00'),
            ['X-Soft-Alert-Secret' => 'wrong-secret']
        );
        $delivery->runLookup();

        $this->assertTrue($delivery->finished());
        $this->assertSame(401, $delivery->responses()[0]['code']);
        $this->assertFalse((bool) $delivery->responses()[0]['body']['ok']);
        $this->assertSame(0, $mock->githubCallCount(), 'unauthorized delivery must not touch GitHub');

        // Lowercase header variant still passes (export checks both cases).
        $ok = $replay->startDelivery(
            $this->payloadX('2026-11-23T18:00:02+00:00'),
            ['x-soft-alert-secret' => self::SECRET]
        );
        $ok->runLookup();
        $this->assertFalse($ok->finished(), 'lowercase secret must pass the gate and reach the list');
        $this->assertGreaterThan(0, $mock->githubCallCount());
    }

    /**
     * Durable sweep против бэклога шторма (35 + 7 + 7): легаси-issues несут
     * полный отпечаток в строке **Fingerprint:** — sweep доказывает дубль и
     * выживает минимальный номер; посторонний issue и PR не тронуты.
     */
    public function test_sweep_closes_storm_backlog_keeping_min_numbers(): void
    {
        $mock = new MockGithub;
        $title1 = $this->titleX();
        $title2 = SoftAlertWorkflowReplay::expectedTitle($this->payload(self::FP_Y, 'irrelevant', 'irrelevant'));
        $title3 = '[soft-alert] backups 33cc44dd';

        $min = [];
        foreach ([[$title1, self::FP_X, 35], [$title2, self::FP_Y, 7], [$title3, self::FP_Y, 7]] as [$title, $fp, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $issue = $mock->seedLegacyIssue($title, $fp);
                if ($i === 0) {
                    $min[$title] = (int) $issue['number'];
                }
            }
        }

        // Noise the sweep must not touch: an unlabeled open issue and a
        // PR sharing the exact soft-alert title (label lets it through the
        // list; the sweep code must exclude it via the pull_request filter).
        $noise = $mock->seedIssue('Unrelated support bug', ['labels' => ['bug']]);
        $pr = $mock->seedPullRequest($title1);

        $replay = new SoftAlertWorkflowReplay($mock, $this->env());
        $replay->runSweep();

        foreach ([$title1, $title2, $title3] as $title) {
            $this->assertSame([$min[$title]], $mock->openNumbersByTitle($title), "survivor for {$title}");
            $this->assertGreaterThan(1, count($mock->closedNumbersByTitle($title)), "backlog closed for {$title}");
            foreach ($mock->closedNumbersByTitle($title) as $closed) {
                $comments = $mock->commentsOn($closed);
                $this->assertCount(1, $comments, "one sweep comment on duplicate #{$closed}");
                $this->assertStringContainsString('periodic soft-alert dedup sweep', $comments[0]);
            }
        }

        // 34 + 6 + 6 = 46 duplicates closed in one pass.
        $this->assertSame(46, count($mock->closedNumbersByTitle($title1))
            + count($mock->closedNumbersByTitle($title2))
            + count($mock->closedNumbersByTitle($title3)));

        // Survivors got no sweep comments; noise and PR untouched.
        foreach ($min as $title => $number) {
            $this->assertSame([], $mock->commentsOn($number), "no comment on survivor #{$number}");
        }
        $this->assertSame('open', $mock->issue((int) $noise['number'])['state']);
        $this->assertSame([], $mock->commentsOn((int) $noise['number']));
        $this->assertSame('open', $mock->issue((int) $pr['number'])['state']);
        $this->assertSame([], $mock->commentsOn((int) $pr['number']));

        $patchedPr = array_filter(
            $mock->calls(),
            static fn (array $c): bool => $c['method'] === 'PATCH'
                && str_ends_with((string) $c['url'], '/issues/'.$pr['number'])
        );
        $this->assertSame([], array_values($patchedPr), 'the sweep must never close the PR');
    }

    /**
     * PR с точным заголовком не считается issue-дедупом: доставка открывает
     * новый issue и НЕ закрывает его «как дубль» против PR.
     */
    public function test_pull_requests_are_ignored_by_delivery_dedup(): void
    {
        $mock = new MockGithub;
        $replay = new SoftAlertWorkflowReplay($mock, $this->env());

        $pr = $mock->seedPullRequest($this->titleX());

        $delivery = $replay->startDelivery($this->payloadX('2026-11-23T18:00:00+00:00'), $this->headers());
        $delivery->runLookup();
        $this->assertFalse($delivery->finished(), 'a PR is not an existing issue match');

        $delivery->runCreate();
        $delivery->runReconcile();
        $this->assertTrue($delivery->finished());

        $created = $delivery->createdNumber();
        $this->assertNotNull($created);
        $this->assertSame('open', $mock->issue((int) $created)['state'], 'new issue must stay open');
        $this->assertSame([], $mock->commentsOn((int) $created), 'not a duplicate: nothing to converge');
        $this->assertSame('open', $mock->issue((int) $pr['number'])['state']);
        $this->assertSame([], $mock->commentsOn((int) $pr['number']));

        $response = $delivery->responses()[0];
        $this->assertSame(202, $response['code']);
        $this->assertArrayNotHasKey('converged', $response['body']);
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Valid orderings of create/reconcile phases after both lookups
     * (per delivery: create strictly before its own reconcile).
     *
     * @return list<list<string>>
     */
    private function interleavings(): array
    {
        $orders = [];
        foreach ($this->permutations(['CA', 'RA', 'CB', 'RB']) as $perm) {
            if (array_search('CA', $perm, true) < array_search('RA', $perm, true)
                && array_search('CB', $perm, true) < array_search('RB', $perm, true)) {
                $orders[] = $perm;
            }
        }
        $this->assertSame(6, count($orders), 'expected the 6 linear extensions of the two delivery chains');

        return $orders;
    }

    /**
     * @param  list<string>  $items
     * @return list<list<string>>
     */
    private function permutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $result = [];
        foreach ($items as $i => $item) {
            $rest = array_values(array_diff_key($items, [$i => true]));
            foreach ($this->permutations($rest) as $perm) {
                $result[] = array_merge([$item], $perm);
            }
        }

        return $result;
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return ['SOFT_ALERT_WEBHOOK_SECRET' => self::SECRET];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Soft-Alert-Secret' => self::SECRET];
    }

    /**
     * @param  string  $fingerprint  sha256-hex from SoftFailureFingerprint
     * @return array<string, mixed>
     */
    private function payload(string $fingerprint, string $occurredAt, string $failureMessage): array
    {
        return [
            'event' => 'cabinet.soft_alert',
            'schema_version' => 1,
            'scope' => 'guards',
            'fingerprint' => $fingerprint,
            'app_url' => 'https://samskrte.ru',
            'host_hint' => '193.232.229.92',
            'playbook' => 'docs/SERVER_SOFT_ALERT_PLAYBOOK.md',
            'suggested' => [
                'diagnose' => 'php artisan ops:soft-remediate --dry-run --json',
            ],
            'failures' => [
                ['message' => $failureMessage, 'severity' => 'soft'],
            ],
            'occurred_at' => $occurredAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadX(string $occurredAt): array
    {
        return $this->payload(self::FP_X, $occurredAt, 'guards/failed-units: telegram-daily.service');
    }

    private function titleX(): string
    {
        return SoftAlertWorkflowReplay::expectedTitle($this->payloadX('irrelevant'));
    }
}
