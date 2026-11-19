<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ServerGuards\ShellSystemInspector;
use ReflectionMethod;
use Tests\TestCase;

/**
 * H6905: живость restic-SFTP ноги читается из журнала её прогонов.
 *
 * Почему отдельный тест на приватный парсер: backupDestinations() обходит
 * настоящие диски (WebDAV в CI недоступен и медленный), а проверяемое здесь —
 * ровно разбор журнала. Журнала нет или в нём нет успешной строки — строки
 * назначения НЕТ: зелёное без данных не заявляем.
 */
class ShellSystemInspectorResticTest extends TestCase
{
    private ?string $log = null;

    protected function tearDown(): void
    {
        if ($this->log !== null) {
            @unlink($this->log);
        }
        parent::tearDown();
    }

    private function withLog(string $body): ShellSystemInspector
    {
        $path = tempnam(sys_get_temp_dir(), 'restic-log-');
        $this->assertNotFalse($path);
        file_put_contents($path, $body);
        $this->log = $path;
        config(['server_guards.restic_backup_log' => $path]);

        return new ShellSystemInspector;
    }

    private function resticRow(ShellSystemInspector $inspector): ?array
    {
        $method = new ReflectionMethod($inspector, 'resticOffsiteDestination');

        /** @var array{disk: string, reachable: bool, newestAt: int, newestBytes: int|null}|null $row */
        $row = $method->invoke($inspector);

        return $row;
    }

    public function test_the_newest_successful_run_defines_the_offsite_freshness(): void
    {
        $inspector = $this->withLog(<<<'LOG'
2026-10-17T09:34:16Z lane=systema dest=sftp size_sanity=OK
2026-10-17T09:34:17Z run_complete overall_rc=0 systema=OK samudra=OK sftp=OK s3=SKIP
2026-10-17T10:31:46Z run_complete overall_rc=0 systema=OK samudra=OK sftp=OK s3=SKIP
2026-10-17T11:32:31Z run_complete overall_rc=0 systema=OK samudra=OK sftp=OK s3=SKIP
LOG);

        $row = $this->resticRow($inspector);

        $this->assertNotNull($row);
        $this->assertSame(ShellSystemInspector::RESTIC_OFFSITE_DISK, $row['disk']);
        $this->assertTrue($row['reachable']);
        $this->assertSame(strtotime('2026-10-17T11:32:31Z'), $row['newestAt']);
        // restic-снапшот атомарен: размер не измеряем, иначе порог
        // BACKUP_MIN_ARCHIVE_MB (класс 11-МиБ обрезков) красил бы живую ногу.
        $this->assertNull($row['newestBytes']);
    }

    public function test_a_failed_run_does_not_count_as_offsite_freshness(): void
    {
        $inspector = $this->withLog(<<<'LOG'
2026-10-17T09:34:17Z run_complete overall_rc=0 systema=OK samudra=OK sftp=OK s3=SKIP
2026-10-17T10:31:46Z run_complete overall_rc=2 systema=FAIL samudra=OK sftp=FAIL s3=SKIP
2026-10-17T11:32:31Z run_fail:systema dest=sftp
LOG);

        $row = $this->resticRow($inspector);

        $this->assertNotNull($row);
        $this->assertSame(strtotime('2026-10-17T09:34:17Z'), $row['newestAt'], 'последняя УСПЕШНАЯ строка, а не последняя вообще');
    }

    public function test_an_empty_journal_yields_no_destination(): void
    {
        $inspector = $this->withLog('');

        $this->assertNull($this->resticRow($inspector), 'пустой журнал — не доказательство живости');
    }

    public function test_only_failures_yield_no_destination(): void
    {
        $inspector = $this->withLog(<<<'LOG'
2026-10-17T10:31:46Z run_complete overall_rc=2 systema=FAIL samudra=OK sftp=FAIL s3=SKIP
2026-10-17T11:32:31Z run_fail:systema dest=sftp
LOG);

        $this->assertNull($this->resticRow($inspector));
    }

    public function test_a_missing_journal_yields_no_destination(): void
    {
        config(['server_guards.restic_backup_log' => sys_get_temp_dir().'/restic-log-that-does-not-exist-'.getmypid()]);

        $this->assertNull($this->resticRow(new ShellSystemInspector));
    }
}
