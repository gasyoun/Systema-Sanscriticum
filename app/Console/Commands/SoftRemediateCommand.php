<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ServerGuards\SoftAutoDeployRemediator;
use Illuminate\Console\Command;

/**
 * Safe-only soft auto-deploy remediation (H2148 B).
 *
 * Origin-equal tracked dirty discard + optional soft fuse clear.
 * See docs/SERVER_SOFT_ALERT_PLAYBOOK.md.
 */
class SoftRemediateCommand extends Command
{
    protected $signature = 'ops:soft-remediate
        {--dry-run : Only report what would change (default if neither dry-run nor apply)}
        {--apply : Apply safe discards (origin-equal dirty)}
        {--apply-breaker-clear : Also remove storage/auto_deploy.disabled when soft + safe}
        {--json : Machine-readable report}
        {--app-dir= : Override application directory (default base_path())}';

    protected $description = 'Safe soft auto-deploy remediation: origin-equal dirty discard; optional soft fuse clear';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') || (bool) $this->option('apply-breaker-clear');
        $dryRun = ! $apply || (bool) $this->option('dry-run');
        // Explicit --apply without --dry-run → live; --dry-run wins if both.
        if ((bool) $this->option('dry-run')) {
            $dryRun = true;
        } elseif ((bool) $this->option('apply') || (bool) $this->option('apply-breaker-clear')) {
            $dryRun = false;
        } else {
            $dryRun = true;
        }

        $appDir = (string) ($this->option('app-dir') ?: base_path());
        $remediator = new SoftAutoDeployRemediator($appDir);
        $report = $remediator->remediate(
            dryRun: $dryRun,
            applyBreakerClear: (bool) $this->option('apply-breaker-clear'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return (int) $report['exit_code'];
        }

        $status = (string) $report['status'];
        $this->line('ops:soft-remediate — '.$status.($dryRun ? ' (dry-run)' : ' (apply)'));
        $this->line('  '.$report['message']);

        if ($report['breaker_present']) {
            $soft = $report['breaker_soft'] === true ? 'soft' : ($report['breaker_soft'] === false ? 'HARD' : '?');
            $this->line('  breaker: present ('.$soft.') last: '.($report['breaker_last_line'] ?? ''));
        } else {
            $this->line('  breaker: absent');
        }

        if ($report['origin_equal'] !== []) {
            $this->line('  origin-equal dirty: '.implode(', ', $report['origin_equal']));
        }
        if ($report['diverging'] !== []) {
            $this->warn('  diverging dirty: '.implode(', ', $report['diverging']));
        }
        if ($report['allowed_dirty'] !== []) {
            $this->line('  allowed dirty (PDF): '.implode(', ', $report['allowed_dirty']));
        }

        foreach ($report['actions'] as $action) {
            $mark = $action['applied'] ? '✓' : '·';
            $this->line("  {$mark} [{$action['type']}] {$action['detail']}");
        }

        if ($status === 'ok' && ! $dryRun) {
            $this->info('Next: bash deploy.sh (if HEAD behind) · php artisan guards:verify · cabinet:probe');
            $this->line('Playbook: docs/SERVER_SOFT_ALERT_PLAYBOOK.md');
        }
        if ($status === 'needs_human') {
            $this->warn('Human/agent judgment required — see docs/SERVER_SOFT_ALERT_PLAYBOOK.md §4.1');
        }

        return (int) $report['exit_code'];
    }
}
