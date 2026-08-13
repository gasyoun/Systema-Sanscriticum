<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\Lifecycle\LifecycleCampaignPreparer;
use App\Services\Crm\Lifecycle\LifecycleEligibility;
use App\Services\Crm\Lifecycle\LifecycleOutcomeReport;
use Illuminate\Console\Command;

/**
 * CRM Wave 2 (H2484) — dry-run (default) or prepare draft campaigns.
 *
 *   php artisan crm:lifecycle-prepare
 *   php artisan crm:lifecycle-prepare --json
 *   php artisan crm:lifecycle-prepare --apply
 *   php artisan crm:lifecycle-prepare --report
 *
 * `--apply` writes drafts only. It never sends. Flag must be ON.
 */
final class CrmLifecyclePrepareCommand extends Command
{
    protected $signature = 'crm:lifecycle-prepare
                            {--rule= : One rule key (default: all three)}
                            {--apply : Write draft campaigns (flag must be ON); default is dry-run}
                            {--follow-ups : Also upsert FollowUpTask rows on open deals}
                            {--report : Print sent/opened/clicked/paid aggregates}
                            {--json : Machine-readable JSON}';

    protected $description = 'CRM Wave 2: dry-run or prepare draft lifecycle campaigns (never sends)';

    public function handle(
        LifecycleCampaignPreparer $preparer,
        LifecycleOutcomeReport $outcomes,
    ): int {
        $rule = $this->option('rule');
        $rule = is_string($rule) && $rule !== '' ? $rule : null;
        if ($rule !== null && ! in_array($rule, LifecycleEligibility::rules(), true)) {
            $this->error('Unknown rule. Use: '.implode(', ', LifecycleEligibility::rules()));

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && ! (bool) config('features.crm_lifecycle_automation')) {
            $this->error('crm_lifecycle_automation is OFF — dry-run only. Pass no --apply, or flip CRM_LIFECYCLE_AUTOMATION.');

            return self::FAILURE;
        }

        $payload = $preparer->run(
            apply: $apply,
            onlyRule: $rule,
            withFollowUps: (bool) $this->option('follow-ups'),
        );

        if ($this->option('report')) {
            $payload['outcomes'] = $outcomes->build();
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($payload['dry_run'] ? 'Dry-run (no drafts written)' : 'Drafts prepared (not sent)');
        $this->line('flag='.($payload['flag'] ? 'on' : 'off'));

        $table = [];
        foreach ($payload['rules'] as $row) {
            $table[] = [
                $row['rule'].' v'.$row['version'],
                $row['candidates'],
                $row['eligible'],
                $row['excluded'],
                $row['suppressed'],
                $row['recovery'],
                $row['deduplicated'],
                $row['campaign_id'] ?? '—',
            ];
        }
        $this->table(
            ['rule', 'cand', 'eligible', 'excl', 'supp', 'recv', 'dedup', 'campaign'],
            $table,
        );

        if (isset($payload['outcomes'])) {
            $this->newLine();
            $this->info('Outcomes as of '.$payload['outcomes']['as_of']);
            $outRows = [];
            foreach ($payload['outcomes']['rules'] as $row) {
                $outRows[] = [
                    $row['rule'],
                    $row['sent'],
                    $row['delivered'],
                    $row['opened'],
                    $row['clicked'],
                    $row['paid'],
                ];
            }
            $this->table(['rule', 'sent', 'delivered', 'opened', 'clicked', 'paid'], $outRows);
        }

        return self::SUCCESS;
    }
}
