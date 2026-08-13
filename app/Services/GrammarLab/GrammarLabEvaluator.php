<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

/**
 * Frozen-query Recall@5 evaluator (H2493).
 * Writes a machine-readable report; never flips the semantic flag.
 */
final class GrammarLabEvaluator
{
    public function __construct(
        private readonly GrammarLabSearch $search,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(string $queriesPath, bool $semantic): array
    {
        $payload = json_decode((string) file_get_contents($queriesPath), true);
        if (! is_array($payload) || ! isset($payload['queries']) || ! is_array($payload['queries'])) {
            throw new \RuntimeException("invalid frozen queries: {$queriesPath}");
        }

        $topK = (int) config('grammar_lab.search.top_k', 10);
        $recallAt = (int) config('grammar_lab.search.recall_at', 5);
        $gate = (float) config('grammar_lab.search.recall_gate', 0.85);

        $rows = [];
        $hits1 = 0;
        $hits5 = 0;
        $rrSum = 0.0;
        $started = hrtime(true);

        foreach ($payload['queries'] as $q) {
            if (! is_array($q)) {
                continue;
            }
            $acceptable = array_map('strval', $q['acceptable_topic_ids'] ?? []);
            $hits = $this->search->search((string) $q['query'], $topK, $semantic);
            $ids = array_column($hits, 'topic_id');
            $rank = null;
            foreach ($ids as $i => $id) {
                if (in_array($id, $acceptable, true)) {
                    $rank = $i + 1;
                    break;
                }
            }
            if ($rank === 1) {
                $hits1++;
            }
            if ($rank !== null && $rank <= $recallAt) {
                $hits5++;
            }
            if ($rank !== null) {
                $rrSum += 1.0 / $rank;
            }
            $rows[] = [
                'id' => $q['id'] ?? null,
                'query' => $q['query'] ?? '',
                'script' => $q['script'] ?? null,
                'intent' => $q['intent'] ?? null,
                'acceptable' => $acceptable,
                'rank' => $rank,
                'top' => array_slice($ids, 0, $recallAt),
            ];
        }

        $n = count($rows);
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        $recall5 = $n > 0 ? $hits5 / $n : 0.0;
        $recall1 = $n > 0 ? $hits1 / $n : 0.0;
        $mrr = $n > 0 ? $rrSum / $n : 0.0;

        return [
            'generated_at' => gmdate('c'),
            'queries_path' => $queriesPath,
            'n' => $n,
            'semantic' => $semantic,
            'semantic_available' => $this->search->semanticAvailable(),
            'recall_at_1' => round($recall1, 4),
            'recall_at_5' => round($recall5, 4),
            'mrr' => round($mrr, 4),
            'hits_at_1' => $hits1,
            'hits_at_5' => $hits5,
            'gate' => $gate,
            'gate_pass' => $recall5 >= $gate,
            'latency_ms' => round($elapsedMs, 1),
            'per_query' => $rows,
        ];
    }
}
