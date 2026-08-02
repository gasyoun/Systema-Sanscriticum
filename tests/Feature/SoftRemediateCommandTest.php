<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ServerGuards\SoftAutoDeployRemediator;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * H2148: safe soft auto-deploy remediation against a real temp git repo.
 */
class SoftRemediateCommandTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'soft-remediate-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($this->repo, 0777, true));
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
        file_put_contents($this->repo.'/tracked.txt', "v1\n");
        mkdir($this->repo.'/config', 0777, true);
        file_put_contents($this->repo.'/config/copy.php', "<?php return ['a' => 1];\n");
        mkdir($this->repo.'/storage', 0777, true);
        $this->git(['add', '-A']);
        $this->git(['commit', '-m', 'init']);
        // Bare remote as origin/main
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

    public function test_clean_tree_no_breaker_is_ok(): void
    {
        $r = (new SoftAutoDeployRemediator($this->repo))->remediate(dryRun: true);
        $this->assertSame('ok', $r['status']);
        $this->assertSame(0, $r['exit_code']);
        $this->assertFalse($r['breaker_present']);
    }

    public function test_origin_equal_dirty_discarded_and_soft_fuse_cleared(): void
    {
        // Dirty file that still matches origin/main (touch content back to same)
        file_put_contents($this->repo.'/config/copy.php', "<?php return ['a' => 1];\n");
        // Force "dirty" by changing mtime content then restoring... actually same content
        // is not dirty. Make it dirty by writing same as origin after a temp change
        // that we reset to origin content: change then write origin content with extra spaces
        // Better: write identical content after `git update-index` — simplest path:
        // modify to v2, commit on another clone is heavy. Use:
        // echo same content as committed — git status is clean.
        // Real origin-equal dirty: commit on main in bare is v1; local checkout dirty with v1
        // after `git show` rewrite. Pattern from deploy H2066: working tree == origin but
        // differs from index? Actually "dirty" means differs from HEAD.
        // Origin-equal means: working tree == origin/main for path, even if != HEAD.
        // Simulate: amend origin ahead, local has working tree equal to NEW origin but HEAD old.
        file_put_contents($this->repo.'/config/copy.php', "<?php return ['a' => 2];\n");
        $this->git(['add', 'config/copy.php']);
        $this->git(['commit', '-m', 'v2']);
        $this->git(['push', 'origin', 'main']);
        // Reset HEAD back one commit but keep working tree at v2 (= origin)
        $this->git(['reset', '--soft', 'HEAD~1']);
        $this->git(['reset', 'HEAD', 'config/copy.php']); // unstage → dirty working tree = v2 = origin

        $status = $this->git(['status', '--porcelain']);
        $this->assertStringContainsString('config/copy.php', $status);

        file_put_contents(
            $this->repo.'/storage/auto_deploy.disabled',
            "2026-08-01T19:30:03Z [blocked-preflight] deploy.sh exit 1\n"
        );

        $dry = (new SoftAutoDeployRemediator($this->repo))->remediate(dryRun: true, applyBreakerClear: true);
        $this->assertSame('ok', $dry['status']);
        $this->assertContains('config/copy.php', $dry['origin_equal']);
        $this->assertTrue(is_file($this->repo.'/storage/auto_deploy.disabled'));

        $live = (new SoftAutoDeployRemediator($this->repo))->remediate(dryRun: false, applyBreakerClear: true);
        $this->assertSame('ok', $live['status']);
        $this->assertSame(0, $live['exit_code']);
        $this->assertFalse(is_file($this->repo.'/storage/auto_deploy.disabled'));
        // After checkout HEAD, working tree matches old HEAD (v1), clean vs HEAD
        $this->assertSame('', trim($this->git(['status', '--porcelain', '--untracked-files=no'])));
    }

    public function test_diverging_dirty_needs_human_and_keeps_fuse(): void
    {
        file_put_contents($this->repo.'/config/copy.php', "<?php return ['unique' => 'hotfix'];\n");
        file_put_contents(
            $this->repo.'/storage/auto_deploy.disabled',
            "2026-08-01T19:30:03Z [blocked-preflight] dirty\n"
        );

        $r = (new SoftAutoDeployRemediator($this->repo))->remediate(dryRun: false, applyBreakerClear: true);
        $this->assertSame('needs_human', $r['status']);
        $this->assertSame(1, $r['exit_code']);
        $this->assertContains('config/copy.php', $r['diverging']);
        $this->assertTrue(is_file($this->repo.'/storage/auto_deploy.disabled'));
        $this->assertStringContainsString('unique', (string) file_get_contents($this->repo.'/config/copy.php'));
    }

    public function test_hard_breaker_never_cleared(): void
    {
        file_put_contents(
            $this->repo.'/storage/auto_deploy.disabled',
            "2026-08-01T12:00:00Z deploy health failed after migrations\n"
        );

        $r = (new SoftAutoDeployRemediator($this->repo))->remediate(dryRun: false, applyBreakerClear: true);
        $this->assertSame('needs_human', $r['status']);
        $this->assertSame(1, $r['exit_code']);
        $this->assertFalse($r['breaker_soft']);
        $this->assertTrue(is_file($this->repo.'/storage/auto_deploy.disabled'));
    }

    public function test_soft_tag_detection(): void
    {
        $r = new SoftAutoDeployRemediator($this->repo);
        $this->assertTrue($r->isSoftBreakerLine('[blocked-preflight] x'));
        $this->assertTrue($r->isSoftBreakerLine('… [timeout-alive] …'));
        $this->assertTrue($r->isSoftBreakerLine('[rolled-back]'));
        $this->assertFalse($r->isSoftBreakerLine('deploy failed hard'));
    }

    public function test_artisan_dry_run_json(): void
    {
        $this->artisan('ops:soft-remediate', [
            '--app-dir' => $this->repo,
            '--json' => true,
            '--dry-run' => true,
        ])->assertExitCode(0);
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
