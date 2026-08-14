<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HindiTgCuratedPractice;
use Illuminate\Console\Command;

/**
 * H2446 — read-only scan of the curated Hindi TG practice store.
 *
 * Works with the flag OFF. Does not call Telegram. Answers are redacted.
 */
class HindiTgPracticeProbeCommand extends Command
{
    protected $signature = 'hindi:tg-practice-probe
        {--json : Print JSON}
        {--path= : Alternate store path (tests / dry-run)}';

    protected $description = 'Read-only curated Hindi TG practice items (answers redacted)';

    public function handle(HindiTgCuratedPractice $practice): int
    {
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : null;
        $report = $practice->probe($path);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(
            'flag='.($report['flag'] ? 'on' : 'off')
            .' accepted='.$report['accepted_count']
            .' raw='.$report['raw_count']
            .' rejected='.count($report['rejected'])
        );
        foreach ($report['items'] as $row) {
            $this->line($row['id'].'  '.$row['type'].'  '.$row['prompt']);
        }

        return self::SUCCESS;
    }
}
