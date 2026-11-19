<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * H7060 — deploy.sh must reconcile managed guards, not just report drift.
 *
 * Row 9 of the 18-10-2026 guard=none incident: a legitimate commit edited a
 * managed file; deploy.sh called guards:verify, printed the drift and stopped.
 * The code deploy was green, the drift was permanent, the auto-deploy fuse
 * burned and 26 alerts over 36 hours changed no file.
 *
 * The executable half of this test is scripts/server_guards/sbin/
 * test_deploy_guards_apply.sh: it replays row 9 in a temp root — edit a managed
 * template, deploy twice, second run must be a no-op with zero diff — and drives
 * deploy_guards_sync.sh with fakes to prove the rollback path stays verify-only.
 */
final class DeployGuardsApplyTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function readRepoFile(string $relative): string
    {
        $path = $this->repoRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $this->assertFileExists($path, $relative.' must exist for the H7060 gate');
        $body = file_get_contents($path);
        $this->assertNotFalse($body, $relative.' must be readable');

        return $body;
    }

    public function test_deploy_sh_applies_managed_guards_after_verify(): void
    {
        $script = $this->readRepoFile('deploy.sh');

        $this->assertStringContainsString(
            'bash "$APP_DIR/scripts/deploy_guards_sync.sh"',
            $script,
            'H7060: deploy.sh must run the verify → dry-run → apply → verify helper',
        );
        $this->assertStringNotContainsString(
            'php artisan guards:verify || GUARDS_DRIFT=1',
            $script,
            'H7060: verify-only was the row-9 defect — it turned a legitimate commit into permanent drift',
        );
        $this->assertStringContainsString(
            'GUARD_SYNC_ARGS+=(--verify-only)',
            $script,
            'H7060: rollback must stay verify-only',
        );
        $this->assertStringContainsString('GUARDS_DRIFT=1', $script);
        $this->assertStringContainsString(
            'Exit 0 (issue #1143) — auto-deploy must not rollback managed-file drift',
            $script,
            'H7060: the fuse/rollback contract is unchanged — drift never rolls a healthy code deploy back',
        );
    }

    public function test_guard_sync_announces_dry_run_and_every_system_change(): void
    {
        $sync = $this->readRepoFile('scripts/deploy_guards_sync.sh');

        $this->assertStringContainsString('--dry-run --diff', $sync);
        $this->assertStringContainsString('GUARDS APPLY:', $sync);
        $this->assertStringContainsString('deploys.log', $sync);
        $this->assertStringContainsString('exit 75', $sync);
        $this->assertStringContainsString('--verify-only', $sync);
    }

    public function test_row_9_fixture_replays_edit_apply_twice_noop(): void
    {
        $fixture = $this->repoRoot().'/scripts/server_guards/sbin/test_deploy_guards_apply.sh';
        $this->assertFileExists($fixture);

        $output = [];
        $rc = 0;
        exec('bash '.escapeshellarg($fixture).' 2>&1', $output, $rc);

        $this->assertSame(
            0,
            $rc,
            "H7060 row-9 fixture failed:\n".implode("\n", $output),
        );
        $this->assertStringContainsString('PASS: H7060 row-9 replay', implode("\n", $output));
    }
}
