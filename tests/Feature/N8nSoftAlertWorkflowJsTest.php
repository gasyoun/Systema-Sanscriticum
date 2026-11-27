<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * H8562 v3: исполняет НАСТОЯЩИЙ JavaScript Code-узлов из n8n-экспорта (не
 * PHP-зеркала) против фикстур трех находок independent review 23-11:
 * (1) пустой лист не убивает ветку create — заголовок строится, совпадений
 * нет; (2) совпадение требует ПОЛНЫЙ отпечаток (маркер + легаси-строка), а
 * не 8-hex префикс заголовка; (3) sweep закрывает только доказуемые дубли и
 * молчит на неоднозначных/коллизионных. Зеркала в tests/Support/N8n/ остаются
 * fail-closed по грамматическим пинам — этот тест второй, независимый свидетель.
 *
 * Пропускается без node на PATH (реплей/контракт несут покрытие на боксах без Node).
 */
class N8nSoftAlertWorkflowJsTest extends TestCase
{
    private const PROBE = 'tests/Support/N8n/soft_alert_js_probe.cjs';

    public function test_exported_code_node_js_executes_the_review_findings(): void
    {
        try {
            (new Process(['node', '--version'], base_path(), null, null, 30))->mustRun();
        } catch (ProcessStartFailedException) {
            $this->markTestSkipped('node is not available — JS-level coverage runs via the pinned PHP mirrors only');
        }

        $probe = new Process(['node', self::PROBE], base_path(), null, null, 120);
        $probe->run();

        $output = $probe->getOutput();

        $this->assertSame(0, $probe->getExitCode(), "actual-JS probe must pass:\n{$output}");
        $this->assertStringContainsString('ALL JS PROBES PASS', $output);
        $this->assertStringNotContainsString('FAIL ', $output);
    }
}
