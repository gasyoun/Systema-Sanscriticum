<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HindiDictionaryDrills;
use Illuminate\Console\Command;

/**
 * H3206 — read-only census of Kostina dictionary entries / generated items.
 */
class HindiDictProbeCommand extends Command
{
    protected $signature = 'hindi:dict-probe
        {--json : Print JSON}';

    protected $description = 'Read-only Kostina Hindi dictionary drill census';

    public function handle(HindiDictionaryDrills $drills): int
    {
        $report = $drills->probe();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->info('entries='.$report['entry_total'].' items='.$report['item_total'].' flag='.($report['flag'] ? 'on' : 'off'));
        foreach ($report['modules'] as $row) {
            $this->line($row['module'].'  entries='.$row['entries'].' items='.$row['items']);
        }

        return self::SUCCESS;
    }
}
