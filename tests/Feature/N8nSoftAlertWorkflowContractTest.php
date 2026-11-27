<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * H8562 v3 контракт n8n-приемника. Independent review (23-11, вечер) дал
 * три transport/logic-находки поверх v2: (1) пустой REST-массив убивал
 * ветку create — первый issue нового отпечатка не создавался без
 * alwaysOutputData; (2) лист без пагинации не видел канонический issue за
 * пределами первой сотни; (3) два различных sha256 могут делить первые
 * восемь hex — дедуп по заголовку склеивал чужие классы. Заголовок
 * остается 8-hex (отображение + легаси), дедуп требует ПОЛНЫЙ отпечаток из
 * тела (структурный маркер нового issue + легаси-строка фолбэком),
 * неоднозначные легаси не матчатся и не закрываются. Дедуп по-прежнему
 * читает строго консистентный REST-лист, никогда /search/issues; секреты
 * в экспорт не вшиваются.
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
     * метки ops-soft-alert и размером страницы 100.
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
            $this->assertSame('100', $query['per_page'] ?? null, "{$name}: documented page size");
        }
    }

    /**
     * Transport-находка review №1: n8n не дает выход у HTTP-узла на пустом
     * массиве — без alwaysOutputData первая доставка нового отпечатка
     * НЕ создавала issue (ветка умирала до create). Флаг обязан стоять на
     * всех трех list-узлах.
     */
    public function test_list_nodes_emit_on_empty_response(): void
    {
        foreach (['GitHub list open issues', 'GitHub reconcile list', 'Sweep list open issues'] as $name) {
            $node = $this->nodesByName()[$name] ?? null;
            $this->assertNotNull($node, "export must keep the '{$name}' node");

            $this->assertTrue(
                (bool) ($node['alwaysOutputData'] ?? false),
                "{$name}: Always Output Data (n8n node setting) must be set — an empty REST array must still emit one empty item so the create branch survives a genuinely empty board"
            );
        }
    }

    /**
     * Transport-находка review №2: лист был ограничен первой сотней без
     * пагинации — канонический issue за пределами 100 был невидим для
     * lookup и sweep. Теперь на всех трех list-узлах стоит ПОДДЕРЖАННАЯ
     * n8n-пагинация из cookbook («Get the next page by number using
     * $pageCount»): Query page = {{ $pageCount + 1 }}, завершение по
     * пустой странице (GitHub отдает bare top-level array), с потолком
     * запросов — те же страницы, на которые указывает Link rel=next.
     */
    public function test_list_nodes_use_supported_n8n_pagination_beyond_the_first_hundred(): void
    {
        foreach (['GitHub list open issues', 'GitHub reconcile list', 'Sweep list open issues'] as $name) {
            $node = $this->nodesByName()[$name] ?? null;
            $this->assertNotNull($node, "export must keep the '{$name}' node");

            $pagination = $node['parameters']['options']['pagination']['pagination'] ?? null;
            $this->assertIsArray($pagination, "{$name}: pagination block missing");

            $this->assertSame('updateAParameterInEachRequest', $pagination['paginationMode'] ?? null, "{$name}: documented pagination mode");
            $this->assertSame('responseIsEmpty', $pagination['paginationCompleteWhen'] ?? null, "{$name}: GitHub returns a bare array — complete on an empty page");

            $pageParameters = $pagination['parameters']['parameters'] ?? null;
            $this->assertIsArray($pageParameters, "{$name}: next-page parameters");
            $this->assertCount(1, $pageParameters, "{$name}: exactly one updated parameter");
            $this->assertSame('qs', $pageParameters[0]['type'] ?? null, "{$name}: update the query string");
            $this->assertSame('page', $pageParameters[0]['name'] ?? null, "{$name}: GitHub page parameter");
            $this->assertSame('={{ $pageCount + 1 }}', $pageParameters[0]['value'] ?? null, "{$name}: documented \$pageCount expression");

            $this->assertTrue((bool) ($pagination['limitPagesFetched'] ?? false), "{$name}: pagination must be bounded");
            $this->assertGreaterThan(1, (int) ($pagination['maxRequests'] ?? 0), "{$name}: max pages bound");
            $this->assertGreaterThanOrEqual(0, (int) ($pagination['requestInterval'] ?? 0), "{$name}: request interval");
        }
    }

    /**
     * Находка review №3: два РАЗЛИЧНЫХ sha256 могут делить первые восемь
     * hex — совпадение по заголовку (8-hex префикс) склеивало чужие классы
     * отказов. Заголовок остается 8-hex для отображения и легаси-совместимости,
     * но дедуп требует ПОЛНЫЙ отпечаток: структурный маркер в теле нового
     * issue (создается вместе с issue), с легаси-строкой
     * **Fingerprint:** как фолбэком для pre-v3 issues. Неоднозначные тела
     * (без доказуемого отпечатка) не матчатся и не закрываются.
     */
    public function test_matching_requires_the_full_fingerprint_not_just_the_eight_hex_title(): void
    {
        $byName = $this->nodesByName();

        $create = (string) ($byName['GitHub create issue']['parameters']['jsonBody'] ?? '');
        $this->assertStringContainsString("body: $('Match exact title').first().json.issue_body", $create, 'created issue body comes from the Match node composer (live-fix 27-11-2026: n8n extendSyntax rejects template literals with escaped backticks/nested interpolation inside ={{ }} jsonBody)');
        $matchCode = (string) ($byName['Match exact title']['parameters']['jsCode'] ?? '');
        $this->assertStringContainsString("<!-- soft-alert-fingerprint:' + (body.fingerprint || '') + ' -->", $matchCode, 'the composer embeds the structured marker interpolating the delivered FULL fingerprint');
        $this->assertStringNotContainsString('slice(0, 8)', $create, 'create must not rebuild the title');

        foreach (['Match exact title', 'Reconcile find rival'] as $name) {
            $code = (string) ($byName[$name]['parameters']['jsCode'] ?? '');
            $this->assertStringContainsString('soft-alert-fingerprint:([0-9a-fA-F]{8,128})', $code, "{$name}: structured marker regex");
            $this->assertStringContainsString('\*\*Fingerprint:\*\*\s*`([0-9a-fA-F]{8,128})`', $code, "{$name}: legacy **Fingerprint:** line fallback");
            $this->assertStringContainsString("fp !== '' && fpOf(i.body) === fp", $code, "{$name}: equality on the FULL fingerprint, never prefix-only");
        }

        $reconcile = (string) ($byName['Reconcile find rival']['parameters']['jsCode'] ?? '');
        $this->assertStringContainsString('&& i.number < mine', $reconcile, 'duplicate needs a LOWER number');

        $sweep = (string) ($byName['Sweep group duplicates']['parameters']['jsCode'] ?? '');
        $this->assertStringContainsString('soft-alert-fingerprint:([0-9a-fA-F]{8,128})', $sweep);
        $this->assertStringContainsString("const key = title + ' :: ' + fp;", $sweep, 'sweep groups by title AND full fingerprint');
        $this->assertStringContainsString('if (!fp) continue;', $sweep, 'ambiguous legacy issues are never closed by the sweep');

        $sweepComment = (string) ($byName['Sweep comment duplicate']['parameters']['jsonBody'] ?? '');
        $this->assertStringContainsString('same title and full fingerprint', $sweepComment);
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
        // The comment node sits AFTER the close node, so $json there is the
        // close response — it must read the duplicate number through the
        // paired-item reference to the group node. (The pre-live-fix URL
        // read $json.number, which exists on neither object.)
        $this->assertStringContainsString("{{ $('Sweep group duplicates').item.json.duplicate_number }}", $commentUrl);
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
