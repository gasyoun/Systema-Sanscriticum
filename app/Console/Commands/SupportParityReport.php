<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Support\SupportParityReportBuilder;
use Illuminate\Console\Command;

/**
 * H2382 — emit the support/JIVO parity acceptance report (aggregate, privacy-safe).
 *
 * Owning command named in the handoff as `support:parity-report --days=14`.
 * Read-only against rollups + flags + school-wide promise counts.
 */
class SupportParityReport extends Command
{
    protected $signature = 'support:parity-report
        {--days=14 : Look-back window in days (1–90)}
        {--json : Machine-readable JSON (default when stdout is non-TTY)}';

    protected $description = 'Support/JIVO parity matrix KPIs: channel mix, flags, topics, outcome slices, GO/HOLD (read-only, no PII).';

    public function handle(SupportParityReportBuilder $builder): int
    {
        $days = (int) $this->option('days');
        $report = $builder->build($days);

        // Always emit JSON when --json; exit code encodes GO/HOLD for ops scripts.
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['verdict']['status'] === 'GO' ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf(
            'Support parity report — %s → %s (%d d) · verdict %s',
            $report['window']['since'],
            $report['window']['until'],
            $report['days'],
            $report['verdict']['status'],
        ));
        $this->line('Privacy: '.$report['privacy']['rule']);
        $this->newLine();

        $this->table(
            ['flag', 'value'],
            collect($report['flags'])->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v])->values()->all(),
        );

        $this->newLine();
        $this->table(
            ['channel', 'conversations', 'incoming', 'outgoing', 'unanswered', 'unresolved>Nh', 'avg first resp s'],
            collect($report['channel_mix'])->map(fn (array $row, string $ch) => [
                $ch,
                $row['conversations'],
                $row['incoming'],
                $row['outgoing'],
                $row['unanswered'],
                $row['unresolved_after_hours'],
                $row['avg_first_response_seconds'] ?? '—',
            ])->values()->all(),
        );

        $this->newLine();
        $kpis = $report['kpis'];
        $this->table(
            ['kpi', 'value'],
            [
                ['open_threads', $kpis['open_threads']],
                ['closed_threads', $kpis['closed_threads']],
                ['stale_open_gt_24h', $kpis['stale_open_threads_gt_24h']],
                ['threads_with_city', $kpis['threads_with_visitor_city']],
                ['threads_with_entry_url', $kpis['threads_with_entry_url']],
                ['lead_captured_threads', $kpis['threads_with_lead_capture']],
                ['live_presences', $kpis['visitor_presences_live']],
                ['crm_follow_ups_open', $kpis['open_follow_up_tasks_crm']],
            ],
        );

        if ($report['topics'] !== []) {
            $this->newLine();
            $this->table(
                ['topic', 'chat_days', 'queries', 'human_replies', 'unanswered', 'web_share'],
                collect($report['topics'])->map(fn (array $t) => [
                    $t['category'],
                    $t['chat_days'],
                    $t['queries'],
                    $t['human_replies'],
                    $t['unanswered'],
                    $t['web_share'] ?? '—',
                ])->all(),
            );
        }

        $this->newLine();
        $this->warn('Outcome slices (aggregate only):');
        foreach ($report['outcome_slices'] as $k => $v) {
            if ($k === 'note') {
                $this->line('  note: '.$v);

                continue;
            }
            $this->line(sprintf('  %s = %s', $k, is_scalar($v) ? (string) $v : json_encode($v)));
        }

        $this->newLine();
        $this->warn('Verdict '.$report['verdict']['status'].':');
        foreach ($report['verdict']['reasons'] as $reason) {
            $this->line('  - '.$reason);
        }

        $this->newLine();
        $this->line('Rollback switches:');
        foreach ($report['rollback'] as $lane => $how) {
            $this->line(sprintf('  %s: %s', $lane, $how));
        }

        return $report['verdict']['status'] === 'GO' ? self::SUCCESS : self::FAILURE;
    }
}
