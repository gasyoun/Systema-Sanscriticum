<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\FaqCorpusParser;
use Tests\TestCase;

/**
 * H2448 offline eval: top-3 hit rate over committed fixture questions.
 * Writes measured table to docs/FAQ_RAG_EVAL_H2448.md when env FAQ_RAG_EVAL_WRITE=1
 * (CI stays read-only — this test only asserts the gate).
 */
class FaqRagEvalTest extends TestCase
{
    public function test_eval_top3_hit_rate_meets_gate(): void
    {
        $fixturePath = base_path('tests/fixtures/faq_rag_eval.json');
        $this->assertFileExists($fixturePath);

        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $items = $fixture['items'] ?? [];
        $this->assertGreaterThanOrEqual(15, count($items), 'eval set must have ≥15 questions');

        $parser = app(FaqCorpusParser::class);
        $chunks = $parser->chunks();
        $this->assertNotEmpty($chunks, 'faq.md must parse into chunks');

        $knownIds = array_flip(array_map(static fn ($c) => $c->chunkId, $chunks));
        $retriever = app(Bm25FaqRetriever::class);

        $hits = 0;
        $rows = [];
        foreach ($items as $item) {
            $expected = $item['expected_chunk_ids'] ?? [];
            foreach ($expected as $eid) {
                $this->assertArrayHasKey($eid, $knownIds, "expected chunk_id missing from corpus: {$eid}");
            }

            $top = $retriever->retrieve((string) $item['question'], 3);
            $topIds = array_map(static fn (array $h) => $h['chunk_id'], $top);
            $hit = count(array_intersect($expected, $topIds)) > 0;
            if ($hit) {
                $hits++;
            }
            $rows[] = [
                'id' => $item['id'],
                'hit' => $hit,
                'top' => $topIds,
                'expected' => $expected,
                'question' => $item['question'],
            ];
        }

        $n = count($items);
        $rate = $hits / $n;
        // Gate: ≥60% top-3 for v1 BM25 (curator-side; not production auto-send).
        $this->assertGreaterThanOrEqual(0.60, $rate, sprintf(
            'top-3 hit rate %.1f%% (%d/%d) below 60%% gate',
            $rate * 100,
            $hits,
            $n,
        ));

        if (filter_var(env('FAQ_RAG_EVAL_WRITE', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->writeReport($n, $hits, $rate, $rows);
        }
    }

    /**
     * @param  list<array{id: string, hit: bool, top: list<string>, expected: list<string>, question: string}>  $rows
     */
    private function writeReport(int $n, int $hits, float $rate, array $rows): void
    {
        $lines = [];
        $lines[] = '# FAQ RAG eval — H2448';
        $lines[] = '';
        $lines[] = '_Created: 08-08-2026 · Last updated: 08-08-2026_';
        $lines[] = '';
        $lines[] = '**Model:** Grok 4.5 (`grok-4.5`)';
        $lines[] = '**Corpus:** `resources/knowledge/faq.md` (ORS-FAQ export — do not hand-edit)';
        $lines[] = '**Retriever:** Okapi BM25 (pure PHP), top-3';
        $lines[] = sprintf('**Result:** **%d/%d = %.1f%%** top-3 hit rate (gate ≥60%%)', $hits, $n, $rate * 100);
        $lines[] = '';
        $lines[] = '| ID | Hit | Question | Top-3 chunk_ids | Expected |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($rows as $r) {
            $lines[] = sprintf(
                '| %s | %s | %s | `%s` | `%s` |',
                $r['id'],
                $r['hit'] ? '✅' : '❌',
                str_replace('|', '\\|', $r['question']),
                implode('`, `', $r['top']),
                implode('`, `', $r['expected']),
            );
        }
        $lines[] = '';
        $lines[] = '## Deploy note';
        $lines[] = '';
        $lines[] = '- Flag `features.faq_rag_suggester` / env `FAQ_RAG_SUGGESTER` defaults **OFF**.';
        $lines[] = '- Money/policy (category D) refuses drafts when best BM25 score < `support.faq_rag.min_score`.';
        $lines[] = '- Never auto-sends; curator Accept only. Prices stay in live catalogue.';
        $lines[] = '';
        $lines[] = '_Dr. Mārcis Gasūns_';
        $lines[] = '';

        $path = base_path('docs/FAQ_RAG_EVAL_H2448.md');
        file_put_contents($path, implode("\n", $lines));
    }
}
