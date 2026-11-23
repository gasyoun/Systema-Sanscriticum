<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * H8562 контракт n8n-приемника: повторная и конкурентная доставка сходятся
 * в один issue (lookup-before-create + comment на существующий), гонка
 * создания лечится post-create reconcile (минимальный номер побеждает,
 * закрывается ровно СВОЙ свежий issue). Секреты в экспорт не вшиваются.
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
     * Lookup-before-create: секрет-ок ведет в поиск, найденный открытый issue
     * дописывается комментарием, create достижим только из ветки «не найден».
     */
    public function test_lookup_before_create_and_comment_on_existing(): void
    {
        $conns = $this->connections();
        $this->assertSame('GitHub search open issue', $conns['IF secret matches']['main'][0][0]['node']);
        $this->assertSame('Respond 401', $conns['IF secret matches']['main'][1][0]['node']);
        $this->assertSame('IF existing open issue', $conns['GitHub search open issue']['main'][0][0]['node']);
        $this->assertSame('GitHub comment on existing', $conns['IF existing open issue']['main'][0][0]['node']);
        $this->assertSame('Respond 200 deduped', $conns['GitHub comment on existing']['main'][0][0]['node']);
        $this->assertSame('GitHub create issue', $conns['IF existing open issue']['main'][1][0]['node']);
    }

    /**
     * Post-create reconcile: create обязан пройти повторный поиск; дубль
     * (есть открытый issue с меньшим номером) комментируется и закрывает
     * ровно свой issue; канал-владелец не пострадал.
     */
    public function test_create_is_reconciled_and_duplicate_closes_itself(): void
    {
        $conns = $this->connections();
        $this->assertSame('GitHub reconcile search', $conns['GitHub create issue']['main'][0][0]['node']);
        $this->assertSame('IF we are the duplicate', $conns['GitHub reconcile search']['main'][0][0]['node']);
        $this->assertSame('GitHub comment duplicate note', $conns['IF we are the duplicate']['main'][0][0]['node']);
        $this->assertSame('GitHub close duplicate', $conns['GitHub comment duplicate note']['main'][0][0]['node']);
        $this->assertSame('Respond 202 converged', $conns['GitHub close duplicate']['main'][0][0]['node']);
        $this->assertSame('Respond 202', $conns['IF we are the duplicate']['main'][1][0]['node']);
    }

    /**
     * Фраза поиска и заголовок создания читают тело ОДНОГО webhook-узла и
     * строятся из одних полей — дедуп не может разъехаться с заголовком.
     */
    public function test_search_phrase_matches_created_title(): void
    {
        $params = [];
        foreach ($this->nodes() as $n) {
            $params[$n['name']] = $n['parameters'] ?? [];
        }

        $q = (string) ($params['GitHub search open issue']['queryParameters']['parameters'][0]['value'] ?? '');
        $create = (string) ($params['GitHub create issue']['jsonBody'] ?? '');

        $this->assertStringContainsString('is:issue is:open in:title', $q);
        $this->assertStringContainsString('.scope', $q);
        $this->assertStringContainsString('.slice(0,8)', $q);

        $this->assertStringContainsString('[soft-alert] ', $create);
        $this->assertStringContainsString('.scope', $create);
        $this->assertStringContainsString('.slice(0,8)', $create);

        $this->assertStringContainsString("$('Webhook soft-alert')", $q);
        $this->assertStringContainsString("$('Webhook soft-alert')", $create);
    }

    /**
     * Правило дубля требует меньший номер и закрывает только СВОЙ issue —
     * два порядка гонки сходятся в более ранний, итерации не нужно.
     */
    public function test_duplicate_rule_closes_only_own_issue_and_needs_lower_number(): void
    {
        $params = [];
        foreach ($this->nodes() as $n) {
            $params[$n['name']] = $n['parameters'] ?? [];
        }

        $ifDup = (string) ($params['IF we are the duplicate']['conditions']['boolean'][0]['value1'] ?? '');
        $this->assertStringContainsString('total_count', $ifDup);
        $this->assertStringContainsString('some(i => i.number <', $ifDup);

        $closeUrl = (string) ($params['GitHub close duplicate']['url'] ?? '');
        $this->assertStringContainsString("$('GitHub create issue').first().json.number", $closeUrl);
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
