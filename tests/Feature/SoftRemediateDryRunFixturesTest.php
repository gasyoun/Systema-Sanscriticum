<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ServerGuards\SoftAutoDeployRemediator;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * H2187: fixture-driven dry-run contracts for ops:soft-remediate.
 *
 * Seeds breaker lines from tests/fixtures/soft_remediate/ and asserts:
 *  - status / exit_code match expected/*.json contracts
 *  - dry-run never mutates working tree or auto_deploy.disabled
 *  - every reported action has applied === false
 *
 * Fence: no live webhook, no prod apply path.
 */
class SoftRemediateDryRunFixturesTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/soft_remediate';

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'soft-remediate-fx-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($this->repo, 0777, true));
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
        file_put_contents($this->repo.'/tracked.txt', "v1\n");
        mkdir($this->repo.'/config', 0777, true);
        file_put_contents($this->repo.'/config/copy.php', "<?php return ['a' => 1];\n");
        mkdir($this->repo.'/storage', 0777, true);
        mkdir($this->repo.'/public/docs', 0777, true);
        file_put_contents($this->repo.'/public/docs/legal.pdf', "%PDF-1.4 fixture\n");
        $this->git(['add', '-A']);
        $this->git(['commit', '-m', 'init']);
        $bare = $this->repo.'-bare.git';
        $this->runCmd(['git', 'clone', '--bare', $this->repo, $bare]);
        $this->git(['remote', 'add', 'origin', $bare]);
        $this->git(['fetch', 'origin']);
        $this->git(['branch', '--set-upstream-to=origin/main', 'main']);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->repo);
        $bare = $this->repo.'-bare.git';
        if (is_dir($bare)) {
            $this->rmTree($bare);
        }
        parent::tearDown();
    }

    public function test_fixture_soft_breaker_tags_are_recognized(): void
    {
        $r = new SoftAutoDeployRemediator($this->repo);
        foreach ([
            'soft_blocked_preflight.txt',
            'soft_blocked_dirty.txt',
            'soft_timeout_alive.txt',
            'soft_rolled_back.txt',
        ] as $name) {
            $line = trim($this->fixtureText('breakers/'.$name));
            $this->assertTrue($r->isSoftBreakerLine($line), $name.' should be soft');
        }
        $hard = trim($this->fixtureText('breakers/hard_health_fail.txt'));
        $this->assertFalse($r->isSoftBreakerLine($hard), 'hard_health_fail should not be soft');
    }

    public function test_dry_run_clean_matches_contract_and_mutates_nothing(): void
    {
        $contract = $this->fixtureJson('expected/dry_run_clean.json');
        $before = $this->snapshotTree();

        $report = (new SoftAutoDeployRemediator($this->repo))->remediate(dryRun: true);

        $this->assertContract($contract, $report);
        $this->assertSame($before, $this->snapshotTree(), 'dry-run must not mutate tree');
        $this->assertAllActionsNotApplied($report);
    }

    public function test_dry_run_origin_equal_soft_fuse_matches_contract_and_keeps_fuse(): void
    {
        $this->seedOriginEqualDirty();
        $this->seedBreaker('breakers/soft_blocked_preflight.txt');
        $contract = $this->fixtureJson('expected/dry_run_origin_equal_soft_fuse.json');
        $before = $this->snapshotTree();
        $dirtyBefore = trim($this->git(['status', '--porcelain', '--untracked-files=no']));

        $report = (new SoftAutoDeployRemediator($this->repo))->remediate(
            dryRun: true,
            applyBreakerClear: true,
        );

        $this->assertContract($contract, $report);
        $this->assertContains('config/copy.php', $report['origin_equal']);
        $this->assertTrue(is_file($this->repo.'/storage/auto_deploy.disabled'));
        $this->assertSame($before, $this->snapshotTree(), 'dry-run must not discard or clear fuse');
        $this->assertSame(
            $dirtyBefore,
            trim($this->git(['status', '--porcelain', '--untracked-files=no'])),
            'dry-run must leave dirty porcelain unchanged'
        );
        $this->assertAllActionsNotApplied($report);
        $this->assertActionTypes($contract['action_types'], $report);
    }

    public function test_dry_run_diverging_needs_human_matches_contract(): void
    {
        file_put_contents($this->repo.'/config/copy.php', "<?php return ['unique' => 'hotfix'];\n");
        $this->seedBreaker('breakers/soft_blocked_preflight.txt');
        $contract = $this->fixtureJson('expected/dry_run_diverging_needs_human.json');
        $before = $this->snapshotTree();

        $report = (new SoftAutoDeployRemediator($this->repo))->remediate(
            dryRun: true,
            applyBreakerClear: true,
        );

        $this->assertContract($contract, $report);
        $this->assertContains('config/copy.php', $report['diverging']);
        $this->assertStringContainsString('unique', (string) file_get_contents($this->repo.'/config/copy.php'));
        $this->assertTrue(is_file($this->repo.'/storage/auto_deploy.disabled'));
        $this->assertSame($before, $this->snapshotTree());
        $this->assertAllActionsNotApplied($report);
        $this->assertActionTypes($contract['action_types'], $report);
    }

    public function test_dry_run_hard_breaker_matches_contract(): void
    {
        $this->seedBreaker('breakers/hard_health_fail.txt');
        $contract = $this->fixtureJson('expected/dry_run_hard_breaker_needs_human.json');
        $before = $this->snapshotTree();

        $report = (new SoftAutoDeployRemediator($this->repo))->remediate(
            dryRun: true,
            applyBreakerClear: true,
        );

        $this->assertContract($contract, $report);
        $this->assertFalse($report['breaker_soft']);
        $this->assertTrue(is_file($this->repo.'/storage/auto_deploy.disabled'));
        $this->assertSame($before, $this->snapshotTree());
        $this->assertAllActionsNotApplied($report);
        $this->assertActionTypes($contract['action_types'], $report);
    }

    public function test_allowed_pdf_dirty_does_not_block_dry_run(): void
    {
        file_put_contents($this->repo.'/public/docs/legal.pdf', "%PDF-1.4 fixture dirty\n");
        $this->seedBreaker('breakers/soft_timeout_alive.txt');

        $report = (new SoftAutoDeployRemediator($this->repo))->remediate(
            dryRun: true,
            applyBreakerClear: true,
        );

        $this->assertSame('ok', $report['status']);
        $this->assertSame(0, $report['exit_code']);
        $this->assertContains('public/docs/legal.pdf', $report['allowed_dirty']);
        $this->assertSame([], $report['diverging']);
        $this->assertTrue(is_file($this->repo.'/storage/auto_deploy.disabled'));
        $this->assertAllActionsNotApplied($report);
    }

    public function test_artisan_default_is_dry_run_json_exit_0_on_clean(): void
    {
        // No --apply → dry-run even without explicit --dry-run
        $this->artisan('ops:soft-remediate', [
            '--app-dir' => $this->repo,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_artisan_dry_run_json_origin_equal_scenario(): void
    {
        $this->seedOriginEqualDirty();
        $this->seedBreaker('breakers/soft_blocked_dirty.txt');

        $this->artisan('ops:soft-remediate', [
            '--app-dir' => $this->repo,
            '--json' => true,
            '--dry-run' => true,
            '--apply-breaker-clear' => true,
        ])->assertExitCode(0);

        $this->assertTrue(
            is_file($this->repo.'/storage/auto_deploy.disabled'),
            'artisan dry-run must not clear fuse even with --apply-breaker-clear'
        );
    }

    /** @return array<string, mixed> */
    private function fixtureJson(string $rel): array
    {
        $path = self::FIXTURES.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $this->assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);

        return $data;
    }

    private function fixtureText(string $rel): string
    {
        $path = self::FIXTURES.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function seedBreaker(string $rel): void
    {
        $body = $this->fixtureText($rel);
        if (! str_ends_with($body, "\n")) {
            $body .= "\n";
        }
        file_put_contents($this->repo.'/storage/auto_deploy.disabled', $body);
    }

    private function seedOriginEqualDirty(): void
    {
        // Working tree == origin/main content, but dirty vs local HEAD (H2148 pattern).
        file_put_contents($this->repo.'/config/copy.php', "<?php return ['a' => 2];\n");
        $this->git(['add', 'config/copy.php']);
        $this->git(['commit', '-m', 'v2']);
        $this->git(['push', 'origin', 'main']);
        $this->git(['reset', '--soft', 'HEAD~1']);
        $this->git(['reset', 'HEAD', 'config/copy.php']);
        $this->assertStringContainsString(
            'config/copy.php',
            $this->git(['status', '--porcelain'])
        );
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $report
     */
    private function assertContract(array $contract, array $report): void
    {
        $this->assertSame($contract['status'], $report['status'], 'status');
        $this->assertSame($contract['exit_code'], $report['exit_code'], 'exit_code');
        if (array_key_exists('breaker_soft', $contract)) {
            $this->assertSame($contract['breaker_soft'], $report['breaker_soft'], 'breaker_soft');
        }
        if (($contract['breaker_present_after_dry_run'] ?? null) === true) {
            $this->assertTrue($report['breaker_present']);
            $this->assertTrue(is_file($this->repo.'/storage/auto_deploy.disabled'));
        }
        if (($contract['breaker_present'] ?? null) === false) {
            $this->assertFalse($report['breaker_present']);
        }
    }

    /** @param  array<string, mixed>  $report */
    private function assertAllActionsNotApplied(array $report): void
    {
        foreach ($report['actions'] as $action) {
            $this->assertFalse(
                $action['applied'],
                'dry-run action must not be applied: '.json_encode($action)
            );
        }
    }

    /**
     * @param  list<string>  $expectedTypes
     * @param  array<string, mixed>  $report
     */
    private function assertActionTypes(array $expectedTypes, array $report): void
    {
        $got = array_values(array_map(
            static fn (array $a): string => (string) $a['type'],
            $report['actions']
        ));
        foreach ($expectedTypes as $type) {
            $this->assertContains($type, $got, 'expected action type '.$type);
        }
    }

    /** @return array{files: array<string, string>, porcelain: string} */
    private function snapshotTree(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repo, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen($this->repo) + 1));
            $files[$rel] = hash_file('sha256', $path) ?: '';
        }
        ksort($files);

        return [
            'files' => $files,
            'porcelain' => trim($this->git(['status', '--porcelain', '--untracked-files=no'])),
        ];
    }

    /** @param  list<string>  $args */
    private function git(array $args): string
    {
        return $this->runCmd(array_merge(['git', '-C', $this->repo], $args));
    }

    /** @param  list<string>  $cmd */
    private function runCmd(array $cmd): string
    {
        $result = Process::timeout(30)->run($cmd);
        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());

        return $result->output();
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
