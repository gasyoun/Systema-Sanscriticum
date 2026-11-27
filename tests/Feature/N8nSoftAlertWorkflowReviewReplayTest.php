<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\N8n\MockGithub;
use Tests\Support\N8n\SoftAlertWorkflowReplay;
use Tests\TestCase;

/**
 * H8562 v3 — реплеи трех transport/logic-находок вечернего independent
 * review (23-11), отдельным классом поверх того же replay-раннера:
 * пустой борд → первый issue создается (alwaysOutputData); канонический
 * issue за пределами первой сотни виден lookup'у и sweep'у (поддержанная
 * n8n-пагинация); два различных отпечатка, делящих первые 8 hex, никогда
 * не склеиваются и не закрываются; неоднозначные легаси (тело без
 * доказуемого отпечатка) не матчатся и НИКОГДА не закрываются — легаси-
 * безопасность миграции. Сходимость конкурентных доставок остается в
 * N8nSoftAlertWorkflowReplayTest.
 */
class N8nSoftAlertWorkflowReviewReplayTest extends TestCase
{
    private const SECRET = 'test-soft-alert-secret';

    /** Storm fingerprint: sha256("guards/failed-units") starts 6fd69840. */
    private const FP_X = '6fd6984000000000000000000000000000000000000000000000000000000000000';

    /** A DISTINCT fingerprint whose first eight hex match FP_X — the review proved two different sha256 values can share them. */
    private const FP_X_COLLIDING = '6fd69840ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    /**
     * Transport-находка №1: n8n не дает выхода у HTTP-узла на пустом
     * массиве — без alwaysOutputData ветка умирала до create, и ПЕРВЫЙ issue
     * нового отпечатка не создавался никогда. Реплей моделирует это честно
     * (ноль items на вход узла = узел не исполняется): на genuinely пустом
     * борде доставка обязана дойти до create, открыть issue с маркером
     * полного отпечатка и ответить 202.
     */
    public function test_first_issue_is_created_on_a_genuinely_empty_issue_list(): void
    {
        $mock = new MockGithub;
        $replay = new SoftAlertWorkflowReplay($mock, $this->env());

        $delivery = $replay->startDelivery($this->payloadX('2026-11-23T20:00:00+00:00'), $this->headers());
        $delivery->runLookup();
        $this->assertFalse($delivery->finished(), 'empty board must still reach the create branch');

        $delivery->runCreate();
        $delivery->runReconcile();
        $this->assertTrue($delivery->finished());

        $created = $delivery->createdNumber();
        $this->assertNotNull($created, 'the FIRST issue for a new fingerprint must be created');

        $issue = $mock->issue((int) $created);
        $this->assertSame($this->titleX(), $issue['title']);
        $this->assertStringContainsString('<!-- soft-alert-fingerprint:'.self::FP_X.' -->', (string) $issue['body'], 'structured full-fingerprint marker in the created body');

        $response = $delivery->responses()[0];
        $this->assertSame(202, $response['code']);
        $this->assertSame([(int) $created], $mock->openNumbersByTitle($this->titleX()));
    }

    /**
     * Transport-находка №2: канонический issue за пределами первой сотни
     * (sort=created&direction=desc) был невидим для lookup и sweep. Два
     * легаси-issue отпечатка X уходят за страницу 1 из-за сотни более
     * свежих issues: доставка X обязана дедупнуться в канонический issue со
     * второй страницы (200 deduped, без create), а sweep обязан закрыть
     * дубль со второй страницы, не тронув шум.
     */
    public function test_canonical_issue_beyond_the_first_page_is_matched_by_lookup_and_sweep(): void
    {
        $mock = new MockGithub;
        $replay = new SoftAlertWorkflowReplay($mock, $this->env());

        $canonical = $mock->seedLegacyIssue($this->titleX(), self::FP_X);
        $staleDup = $mock->seedLegacyIssue($this->titleX(), self::FP_X);
        for ($i = 0; $i < 100; $i++) {
            $mock->seedLegacyIssue(sprintf('[soft-alert] guards %08x', 0x90000000 + $i), self::FP_X);
        }

        // Delivery of X dedups onto the canonical issue beyond page one.
        $delivery = $replay->startDelivery($this->payloadX('2026-11-23T21:00:00+00:00'), $this->headers());
        $delivery->runLookup();
        $this->assertTrue($delivery->finished(), 'the canonical match must be found past page one');
        $this->assertNull($delivery->createdNumber(), 'no new issue for an existing canonical fingerprint');

        $response = $delivery->responses()[0];
        $this->assertSame(200, $response['code']);
        $this->assertTrue((bool) $response['body']['deduped']);

        $secondPages = array_values(array_filter(
            $mock->calls(),
            static fn (array $c): bool => str_contains((string) $c['url'], 'page=2')
        ));
        $this->assertNotSame([], $secondPages, 'the receiver must have walked past page one');

        // The comment landed on the canonical (lowest-number) X issue.
        $this->assertCount(1, $mock->commentsOn((int) $canonical['number']));
        $this->assertStringContainsString('Same fingerprint re-fired', $mock->commentsOn((int) $canonical['number'])[0]);

        // The sweep closes the page-two duplicate, keeps the canonical.
        $replay->runSweep();
        $this->assertSame([(int) $canonical['number']], $mock->openNumbersByTitle($this->titleX()));
        $this->assertSame([(int) $staleDup['number']], $mock->closedNumbersByTitle($this->titleX()));
        $this->assertStringContainsString('periodic soft-alert dedup sweep', $mock->commentsOn((int) $staleDup['number'])[0]);

        $patches = array_values(array_filter(
            $mock->calls(),
            static fn (array $c): bool => $c['method'] === 'PATCH'
        ));
        $this->assertCount(1, $patches, 'the sweep closes exactly the page-two duplicate');
        $this->assertStringEndsWith('/issues/'.$staleDup['number'], (string) $patches[0]['url']);
    }

    /**
     * Находка №3: два РАЗЛИЧНЫХ sha256 делят первые восемь hex. Коллизионный
     * отпечаток не дедупнится в issue X (создает свой), reconcile не считает
     * его дублем, sweep не «лечит» пару — оба issues остаются открытыми,
     * потому что это не дубли: различение новых классов сохранено.
     */
    public function test_distinct_fingerprints_sharing_the_eight_hex_prefix_never_dedup_or_close(): void
    {
        $mock = new MockGithub;
        $replay = new SoftAlertWorkflowReplay($mock, $this->env());

        $first = $replay->startDelivery($this->payloadX('2026-11-23T20:00:00+00:00'), $this->headers());
        $first->runLookup();
        $first->runCreate();
        $first->runReconcile();
        $this->assertTrue($first->finished());
        $xNumber = $first->createdNumber();
        $this->assertNotNull($xNumber);

        // A genuinely distinct fingerprint whose first eight hex match X.
        $colliding = $replay->startDelivery(
            $this->payload(self::FP_X_COLLIDING, '2026-11-23T20:01:00+00:00', 'guards/other-class: distinct failure'),
            $this->headers()
        );
        $colliding->runLookup();
        $this->assertFalse($colliding->finished(), 'a distinct fingerprint sharing the 8-hex title prefix must create its own issue');

        $colliding->runCreate();
        $colliding->runReconcile();
        $this->assertTrue($colliding->finished());

        $collidingNumber = $colliding->createdNumber();
        $this->assertNotNull($collidingNumber);
        $this->assertNotSame($xNumber, $collidingNumber);

        $title = $this->titleX();
        $this->assertCount(2, $mock->openNumbersByTitle($title), 'two distinct fingerprints share the title — both stay open');

        // The two issue bodies carry their OWN full fingerprints.
        $this->assertStringContainsString('<!-- soft-alert-fingerprint:'.self::FP_X.' -->', (string) $mock->issue((int) $xNumber)['body']);
        $this->assertStringContainsString('<!-- soft-alert-fingerprint:'.self::FP_X_COLLIDING.' -->', (string) $mock->issue((int) $collidingNumber)['body']);

        // The durable sweep must NOT "fix" this pair: they are not duplicates.
        $replay->runSweep();
        $this->assertCount(2, $mock->openNumbersByTitle($title), 'no closure on a shared 8-hex prefix');
        $this->assertSame([], $mock->closedNumbersByTitle($title));
        $this->assertSame([], $mock->commentsOn((int) $xNumber), 'X issue is not a duplicate of anything');
        $this->assertSame([], $mock->commentsOn((int) $collidingNumber));

        // Same-fingerprint dedup still works around the collision: a
        // repeat delivery of X comments on X's own issue, not on the rival.
        $again = $replay->startDelivery($this->payloadX('2026-11-23T20:02:00+00:00'), $this->headers());
        $again->runLookup();
        $this->assertTrue($again->finished());
        $this->assertNull($again->createdNumber());
        $this->assertSame(200, $again->responses()[0]['code']);
        $this->assertCount(1, $mock->commentsOn((int) $xNumber));
        $this->assertStringContainsString('Same fingerprint re-fired', $mock->commentsOn((int) $xNumber)[0]);
        $this->assertSame([], $mock->commentsOn((int) $collidingNumber), 'the colliding issue stays out of X re-fires');
    }

    /**
     * Легаси-безопасность (миграция): issue без доказуемого отпечатка в теле
     * — «неоднозначный» — никогда не считается совпадением (это мог быть
     * другой отпечаток с тем же 8-hex префиксом) и НИКОГДА не закрывается
     * sweep'ом. Повторная доставка открывает свой маркер-issue и дедупнится
     * уже в него; неоднозначный легаси остается открытым для человека.
     */
    public function test_ambiguous_legacy_issues_are_never_deduped_onto_or_closed(): void
    {
        $mock = new MockGithub;
        $replay = new SoftAlertWorkflowReplay($mock, $this->env());

        $ambiguous = $mock->seedIssue($this->titleX(), ['body' => 'soft cabinet alert — body carries no fingerprint']);

        // A re-fire of X must not attach to the ambiguous issue.
        $delivery = $replay->startDelivery($this->payloadX('2026-11-23T22:00:00+00:00'), $this->headers());
        $delivery->runLookup();
        $this->assertFalse($delivery->finished(), 'ambiguous legacy is not a proven match — create a fresh issue');

        $delivery->runCreate();
        $delivery->runReconcile();

        $created = $delivery->createdNumber();
        $this->assertNotNull($created);
        $this->assertNotSame((int) $ambiguous['number'], (int) $created);

        // The sweep closes nothing: the ambiguous issue is skipped, the
        // fresh marker issue is unique in its (title, fingerprint) group.
        $replay->runSweep();
        $this->assertSame([], $mock->closedNumbersByTitle($this->titleX()), 'ambiguous prefixes must never trigger closures');
        $this->assertCount(2, $mock->openNumbersByTitle($this->titleX()));
        $this->assertSame('open', $mock->issue((int) $ambiguous['number'])['state']);
        $this->assertSame([], $mock->commentsOn((int) $ambiguous['number']));

        // A repeat delivery of X dedups onto the PROVEN marker issue; the
        // ambiguous issue stays out of it — a human merges it if it is real.
        $again = $replay->startDelivery($this->payloadX('2026-11-23T22:30:00+00:00'), $this->headers());
        $again->runLookup();
        $this->assertTrue($again->finished());
        $this->assertNull($again->createdNumber());

        $response = $again->responses()[0];
        $this->assertSame(200, $response['code']);
        $this->assertTrue((bool) $response['body']['deduped']);

        $this->assertCount(1, $mock->commentsOn((int) $created));
        $this->assertStringContainsString('Same fingerprint re-fired', $mock->commentsOn((int) $created)[0]);
        $this->assertSame([], $mock->commentsOn((int) $ambiguous['number']), 'the ambiguous issue is never commented into');

        // And the sweep still leaves both open.
        $replay->runSweep();
        $this->assertSame([], $mock->closedNumbersByTitle($this->titleX()));
    }

    // ---------------------------------------------------------------- Helpers

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
