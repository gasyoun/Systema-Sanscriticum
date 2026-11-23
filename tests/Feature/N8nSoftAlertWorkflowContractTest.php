<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * H8562 v2 контракт n8n-приемника. Независимый review FAIL: две конкурентные
 * доставки создают по issue, а НЕМЕДЛЕННЫЙ search-reconcile из-за лага
 * индекса видит только свой issue — никто не закрывается, дубли остаются.
 * Поэтому весь dedup читает строго консистентный REST-лист
 * (GET /repos/…/issues), а не лагающий /search/issues; заголовок строится
 * в ОДНОМ узле (Match exact title), create и reconcile читают его оттуда;
 * durable-сходимость добивает плановый sweep. Секреты в экспорт не вшиваются.
 */
class N8nSoftAlertWorkflowContractTest extends TestCase
{
    private const EXPORT = 'docs/n8n/soft-alert-to-github-issue.workflow.json';

    /**
     * @return list<array{name: string, parameters: array<string, mixed>}>
     */
    private function nodes(): array
    {
        $path = base_path(self::EXPORT);
        Assert::assertFileExists($path, 'n8n export must stay in the repo');
        $data = json_decode((string) file_get_contents($path), true);
        Assert::assertIsArray($data);
        Assert::assertIsArray($data['nodes'] ?? null);

        return $data['nodes'];
    }

    /**
     * @return array<string, array<int, array<int, array<string, mixed>>>>
     */
    private function connections(): array
    {
        $data = json_decode((string) file_get_contents(base_path(self::EXPORT)), true);
        Assert::assertIsArray($data);
        Assert::assertIsArray($data['connections'] ?? null);

        return $data['connections'];
    }

    /**
     * @return array<string, array<string, mixed>> node name => node definition
     */
    private function nodesByName(): array
    {
        $byName = [];
        foreach ($this->nodes() as $node) {
            $byName[(string) $node['name']] = $node;
        }

        return $byName;
    }

    /**
     * Ядро фикса: в экспорте НЕТ обращений к /search/issues — дедуп не
     * может зависеть от лагающего поискового индекса (review FAIL).
     * Оба чтения идут строго консистентным REST-листом с контрактом
     * метки ops-soft-alert и честным окном в 100 свежих открытых issues.
     */
    public function test_dedup_reads_the_consistent_issue_list_not_the_lagged_search_index(): void
    {
        $raw = (string) file_get_contents(base_path(self::EXPORT));
        $this->assertStringNotContainsString('/search/issues', $raw);
        $this->assertStringNotContainsString('total_count', $raw);

        foreach (['GitHub list open issues', 'GitHub reconcile list', 'Sweep list open issues'] as $name) {
            $node = $this->nodesByName()[$name] ?? null;
            $this->assertNotNull($node, "export must keep the '{$name}' node");

            $this->assertSame('GET', $node['parameters']['method'] ?? null);
            $this->assertStringContainsString(
                'https://api.github.com/repos/gasyoun/Systema-Sanscriticum/issues',
                (string) ($node['parameters']['url'] ?? ''),
            );

            $query = [];
            foreach (($node['parameters']['queryParameters']['parameters'] ?? []) as $p) {
                $query[(string) $p['name']] = (string) $p['value'];
            }
            $this->assertSame('open', $query['state'] ?? null, "{$name}: state=open");
            $this->assertSame('ops-soft-alert', $query['labels'] ?? null, "{$name}: label contract");
            $this->assertSame('100', $query['per_page'] ?? null, "{$name}: documented scan window");
        }
    }

    /**
     * Lookup-before-create: секрет-ок ведет в список, найденный открытый
     * issue дописывается комментарием, create достижим только из ветки
     * «не найден».
     */
    public function test_lookup_before_create_and_comment_on_existing(): void
    {
        $conns = $this->connections();
        $this->assertSame('GitHub list open issues', $conns['IF secret matches']['main'][0][0]['node']);
        $this->assertSame('Respond 401', $conns['IF secret matches']['main'][1][0]['node']);
        $this->assertSame('Match exact title', $conns['GitHub list open issues']['main'][0][0]['node']);
        $this->assertSame('IF existing open issue', $conns['Match exact title']['main'][0][0]['node']);
        $this->assertSame('GitHub comment on existing', $conns['IF existing open issue']['main'][0][0]['node']);
        $this->assertSame('Respond 200 deduped', $conns['GitHub comment on existing']['main'][0][0]['node']);
        $this->assertSame('GitHub create issue', $conns['IF existing open issue']['main'][1][0]['node']);
    }

    /**
     * Post-create reconcile: create обязан перечитать консистентный список;
     * дубль (есть открытый issue с меньшим номером) комментируется и
     * закрывает ровно свой issue; канал-владелец не пострадал.
     */
    public function test_create_is_reconciled_and_duplicate_closes_only_itself(): void
    {
        $conns = $this->connections();
        $this->assertSame('GitHub reconcile list', $conns['GitHub create issue']['main'][0][0]['node']);
        $this->assertSame('Reconcile find rival', $conns['GitHub reconcile list']['main'][0][0]['node']);
        $this->assertSame('IF we are the duplicate', $conns['Reconcile find rival']['main'][0][0]['node']);
        $this->assertSame('GitHub comment duplicate note', $conns['IF we are the duplicate']['main'][0][0]['node']);
        $this->assertSame('Respond 202', $conns['IF we are the duplicate']['main'][1][0]['node']);
        $this->assertSame('GitHub close duplicate', $conns['GitHub comment duplicate note']['main'][0][0]['node']);
        $this->assertSame('Respond 202 converged', $conns['GitHub close duplicate']['main'][0][0]['node']);

        $byName = $this->nodesByName();
        $close = (string) ($byName['GitHub close duplicate']['parameters']['url'] ?? '');
        $this->assertStringContainsString("$('GitHub create issue').first().json.number", $close);
    }

    /**
     * Заголовок строится в ОДНОМ узле: create и reconcile читают
     * expected_title из «Match exact title» — дедуп не может разъехаться
     * с заголовком (у старого дизайна были два независимых выражения).
     */
    public function test_title_has_a_single_source_shared_by_create_and_reconcile(): void
    {
        $byName = $this->nodesByName();

        $matchCode = (string) ($byName['Match exact title']['parameters']['jsCode'] ?? '');
        $this->assertStringContainsString("const expected = '[soft-alert] ' + (body.scope || 'unknown')", $matchCode);
        $this->assertStringContainsString("(body.fingerprint || '').slice(0, 8)", $matchCode);

        $create = (string) ($byName['GitHub create issue']['parameters']['jsonBody'] ?? '');
        $this->assertStringContainsString("title: $('Match exact title').first().json.expected_title", $create);
        $this->assertStringNotContainsString('slice(0, 8)', $create, 'create must not rebuild the title');

        $reconcileCode = (string) ($byName['Reconcile find rival']['parameters']['jsCode'] ?? '');
        $this->assertStringContainsString("const expected = $('Match exact title').first().json.expected_title;", $reconcileCode);
        $this->assertStringContainsString('&& i.number < mine', $reconcileCode, 'duplicate needs a LOWER number');
    }

    /**
     * Durable sweep: плановый проход группирует открытые ops-soft-alert
     * issues по точному заголовку, выживает минимальный номер, дубли
     * закрываются по одному с комментарием-ссылкой.
     */
    public function test_sweep_is_scheduled_and_keeps_the_lowest_numbered_issue(): void
    {
        $conns = $this->connections();
        $this->assertSame('Sweep list open issues', $conns['Sweep schedule']['main'][0][0]['node']);
        $this->assertSame('Sweep group duplicates', $conns['Sweep list open issues']['main'][0][0]['node']);
        $this->assertSame('Sweep close duplicate', $conns['Sweep group duplicates']['main'][0][0]['node']);
        $this->assertSame('Sweep comment duplicate', $conns['Sweep close duplicate']['main'][0][0]['node']);

        $byName = $this->nodesByName();

        $trigger = $byName['Sweep schedule'] ?? null;
        $this->assertNotNull($trigger);
        $this->assertSame('n8n-nodes-base.scheduleTrigger', $trigger['type']);
        $this->assertSame(6, $trigger['parameters']['rule']['interval'][0]['hoursInterval'] ?? null);

        $groupCode = (string) ($byName['Sweep group duplicates']['parameters']['jsCode'] ?? '');
        $this->assertStringContainsString("title.startsWith('[soft-alert] ')", $groupCode);
        $this->assertStringContainsString('sort((a, b) => a.number - b.number)', $groupCode);
        $this->assertStringContainsString('group.slice(1)', $groupCode, 'only non-minimal duplicates close');

        $closeUrl = (string) ($byName['Sweep close duplicate']['parameters']['url'] ?? '');
        $this->assertStringContainsString('{{$json.duplicate_number}}', $closeUrl);

        $commentUrl = (string) ($byName['Sweep comment duplicate']['parameters']['url'] ?? '');
        $this->assertStringContainsString('{{$json.number}}', $commentUrl);
    }

    /**
     * Секреты не вшиваются в экспорт: проверка секрета остается на env,
     * токенов в узлах нет.
     */
    public function test_no_secrets_baked_into_export(): void
    {
        $raw = (string) file_get_contents(base_path(self::EXPORT));
        $this->assertStringNotContainsString('ghp_', $raw);
        $this->assertStringNotContainsString('github_pat_', $raw);
        $this->assertStringNotContainsString('Bearer ', $raw);
        $this->assertStringContainsString('$env.SOFT_ALERT_WEBHOOK_SECRET', $raw);
    }
}
