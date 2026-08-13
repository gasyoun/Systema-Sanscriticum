<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GrammarLab\GrammarLabEvaluator;
use App\Services\GrammarLab\GrammarLabImporter;
use Illuminate\Console\Command;

/**
 * H2493 — frozen multilingual retrieval report.
 *
 *   php artisan grammar-lab:eval-search
 *   php artisan grammar-lab:eval-search --semantic
 */
class EvalGrammarLabSearch extends Command
{
    protected $signature = 'grammar-lab:eval-search
                            {--semantic : Score hybrid (still no-ops vector unless semantic_ready)}
                            {--out= : Write JSON report path (default: storage/app/grammar_lab_eval.json)}';

    protected $description = 'Evaluate Grammar Lab search against the frozen G1 query set';

    public function handle(GrammarLabEvaluator $evaluator, GrammarLabImporter $importer): int
    {
        $vendor = $importer->vendorDir();
        $queries = $vendor.DIRECTORY_SEPARATOR.'frozen_queries.json';
        if (! is_file($queries)) {
            $this->error("missing {$queries} — run grammar-lab:sync first");

            return self::FAILURE;
        }

        $report = $evaluator->evaluate($queries, (bool) $this->option('semantic'));
        $out = $this->option('out');
        $path = is_string($out) && $out !== ''
            ? $out
            : storage_path('app/grammar_lab_eval.json');
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("cannot create {$dir}");

            return self::FAILURE;
        }
        $slim = $report;
        file_put_contents(
            $path,
            json_encode($slim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        $this->line('n='.$report['n']);
        $this->line('recall_at_1='.$report['recall_at_1']);
        $this->line('recall_at_5='.$report['recall_at_5']);
        $this->line('mrr='.$report['mrr']);
        $this->line('gate_pass='.($report['gate_pass'] ? 'true' : 'false'));
        $this->line('semantic='.($report['semantic'] ? 'true' : 'false'));
        $this->line('semantic_available='.($report['semantic_available'] ? 'true' : 'false'));
        $this->line('latency_ms='.$report['latency_ms']);
        $this->line('report='.$path);

        return $report['gate_pass'] ? self::SUCCESS : self::FAILURE;
    }
}
