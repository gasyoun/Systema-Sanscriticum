<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GrammarLab\GrammarLabPilot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * H2495 — anonymized pilot aggregates with exact denominators. No identity.
 */
class GrammarLabPilotReport extends Command
{
    protected $signature = 'grammar-lab:pilot-report {--write= : Optional path to write JSON}';

    protected $description = 'Print anonymized Grammar Lab pilot aggregates (no PII)';

    public function handle(GrammarLabPilot $pilot): int
    {
        $report = $pilot->report();
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        $this->output->write($json);
        $path = $this->option('write');
        if (is_string($path) && $path !== '') {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
        }

        return self::SUCCESS;
    }
}
