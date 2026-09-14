<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Support\Faq\Bm25FaqRetriever;
use Illuminate\Console\Command;

/**
 * H3766 B5 — вывести порог BM25-скора, ниже которого теневой автоответ не имеет
 * права даже думать об отправке.
 *
 * Считает по committed-фикстуре tests/fixtures/faq_rag_eval.json: для каждого
 * кандидата в пороги берётся точность top-1 среди вопросов, ЧЕЙ ЛУЧШИЙ СКОР ≥
 * порога («precision at threshold»), и покрытие — доля вопросов, доживших до
 * порога. Ruling R3 требует ≥95 % точности; там, где полосы скоров верных и
 * неверных ответов пересекаются, такого порога не существует — команда честно
 * пишет «недостижимо» и показывает лучшее достижимое, а не подгоняет число.
 */
class FaqRagScoreFloor extends Command
{
    protected $signature = 'faq:score-floor
        {--precision=0.95 : требуемая точность top-1 при пороге}
        {--min-coverage=0.30 : минимальная доля вопросов категории, доживающих до порога}
        {--fixture=tests/fixtures/faq_rag_eval.json : путь к eval-набору}';

    protected $description = 'H3766 B5: derive the per-category BM25 score floor at which top-1 precision reaches the ruling R3 bar';

    public function handle(Bm25FaqRetriever $retriever): int
    {
        $path = base_path((string) $this->option('fixture'));
        if (! is_file($path)) {
            $this->error("no fixture at {$path}");

            return self::FAILURE;
        }

        $wantPrecision = (float) $this->option('precision');
        $minCoverage = (float) $this->option('min-coverage');

        /** @var array{items: list<array<string, mixed>>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, list<array{score: float, correct: bool}>> $byCategory */
        $byCategory = [];
        foreach ($fixture['items'] as $item) {
            $hits = $retriever->retrieve((string) $item['question'], 1);
            if ($hits === []) {
                continue;
            }
            $category = (string) ($item['category'] ?? '-');
            $byCategory[$category][] = [
                'score' => (float) ($hits[0]['score'] ?? 0.0),
                'correct' => in_array($hits[0]['chunk_id'], (array) $item['expected_chunk_ids'], true),
            ];
        }
        ksort($byCategory);

        $this->line('| Кат. | N | top-1 без порога | Порог ≥'.sprintf('%.0f%%', $wantPrecision * 100).' | Точность | Покрытие |');
        $this->line('|---|---|---|---|---|---|');

        foreach ($byCategory as $category => $rows) {
            $n = count($rows);
            $base = count(array_filter($rows, static fn (array $r): bool => $r['correct'])) / $n;
            $best = $this->bestThreshold($rows, $wantPrecision, $minCoverage);

            $this->line(sprintf(
                '| %s | %d | %.0f%% | %s | %.0f%% | %.0f%% |',
                $category,
                $n,
                $base * 100,
                $best['reached'] ? sprintf('%.1f', $best['threshold']) : 'недостижимо',
                $best['precision'] * 100,
                $best['coverage'] * 100,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{score: float, correct: bool}>  $rows
     * @return array{reached: bool, threshold: float, precision: float, coverage: float}
     */
    private function bestThreshold(array $rows, float $wantPrecision, float $minCoverage): array
    {
        $candidates = array_values(array_unique(array_map(static fn (array $r): float => $r['score'], $rows)));
        sort($candidates);

        $total = count($rows);
        $fallback = ['reached' => false, 'threshold' => 0.0, 'precision' => 0.0, 'coverage' => 0.0];

        foreach ($candidates as $threshold) {
            $kept = array_values(array_filter($rows, static fn (array $r): bool => $r['score'] >= $threshold));
            $coverage = count($kept) / $total;
            if ($coverage < $minCoverage) {
                break;
            }
            $precision = count(array_filter($kept, static fn (array $r): bool => $r['correct'])) / count($kept);

            if ($precision >= $wantPrecision) {
                return ['reached' => true, 'threshold' => $threshold, 'precision' => $precision, 'coverage' => $coverage];
            }
            if ($precision > $fallback['precision']) {
                $fallback = ['reached' => false, 'threshold' => $threshold, 'precision' => $precision, 'coverage' => $coverage];
            }
        }

        return $fallback;
    }
}
