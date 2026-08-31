<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\FaqCorpusParser;
use Tests\TestCase;

/**
 * H2448 offline eval, расширен H3766 B2: top-3 hit rate + recall@5 + MRR над
 * committed-фикстурой tests/fixtures/faq_rag_eval.json (100 вопросов).
 *
 * Гейт — ХРАПОВИК: пороги ниже равны измеренному базлайну минус запас на шум.
 * Любая правка ретривера, которая роняет метрику, валит тест; правка, которая
 * поднимает её, обязана поднять и константу здесь (H3766 B3: «one tweak per
 * commit; each must raise recall@5 or it reverts»).
 *
 * Отчёт пишется в docs/FAQ_RAG_EVAL_H2448.md при FAQ_RAG_EVAL_WRITE=1;
 * CI остаётся read-only и только проверяет гейт.
 */
class FaqRagEvalTest extends TestCase
{
    /** Ратчет H3766 B2 — базлайн 100-вопросного набора, замер 31-08-2026 до тюнинга B3. */
    private const GATE_TOP3 = 0.67;

    private const GATE_RECALL5 = 0.69;

    private const GATE_MRR = 0.57;

    public function test_eval_metrics_meet_ratchet_gate(): void
    {
        $fixturePath = base_path('tests/fixtures/faq_rag_eval.json');
        $this->assertFileExists($fixturePath);

        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $items = $fixture['items'] ?? [];
        $this->assertGreaterThanOrEqual(100, count($items), 'eval set must have ≥100 questions (H3766 B1)');

        $parser = app(FaqCorpusParser::class);
        $chunks = $parser->chunks();
        $this->assertNotEmpty($chunks, 'faq.md must parse into chunks');

        $knownIds = array_flip(array_map(static fn ($c) => $c->chunkId, $chunks));
        $retriever = app(Bm25FaqRetriever::class);

        $metrics = $this->measure($items, $retriever, $knownIds);

        $this->assertGreaterThanOrEqual(self::GATE_TOP3, $metrics['top3'], sprintf(
            'top-3 hit rate %.1f%% below ratchet %.1f%%',
            $metrics['top3'] * 100,
            self::GATE_TOP3 * 100,
        ));
        $this->assertGreaterThanOrEqual(self::GATE_RECALL5, $metrics['recall5'], sprintf(
            'recall@5 %.1f%% below ratchet %.1f%%',
            $metrics['recall5'] * 100,
            self::GATE_RECALL5 * 100,
        ));
        $this->assertGreaterThanOrEqual(self::GATE_MRR, $metrics['mrr'], sprintf(
            'MRR %.3f below ratchet %.3f',
            $metrics['mrr'],
            self::GATE_MRR,
        ));

        if (filter_var(env('FAQ_RAG_EVAL_WRITE', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->writeReport($metrics);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $knownIds
     * @return array{n: int, top3: float, recall5: float, mrr: float, rows: list<array<string, mixed>>, by_category: array<string, array{n: int, top3: int, recall5: int, mrr: float}>}
     */
    private function measure(array $items, Bm25FaqRetriever $retriever, array $knownIds): array
    {
        $top3Hits = 0;
        $recall5Hits = 0;
        $rrSum = 0.0;
        $rows = [];
        $byCategory = [];

        foreach ($items as $item) {
            /** @var list<string> $expected */
            $expected = $item['expected_chunk_ids'] ?? [];
            foreach ($expected as $eid) {
                $this->assertArrayHasKey($eid, $knownIds, "expected chunk_id missing from corpus: {$eid}");
            }

            $top = $retriever->retrieve((string) $item['question'], 5);
            $topIds = array_map(static fn (array $h) => $h['chunk_id'], $top);

            $rank = 0;
            foreach ($topIds as $i => $cid) {
                if (in_array($cid, $expected, true)) {
                    $rank = $i + 1;
                    break;
                }
            }

            $top3 = $rank > 0 && $rank <= 3;
            $recall5 = $rank > 0;
            $rr = $rank > 0 ? 1.0 / $rank : 0.0;

            $top3Hits += $top3 ? 1 : 0;
            $recall5Hits += $recall5 ? 1 : 0;
            $rrSum += $rr;

            $category = (string) ($item['category'] ?? '-');
            $byCategory[$category] ??= ['n' => 0, 'top3' => 0, 'recall5' => 0, 'mrr' => 0.0];
            $byCategory[$category]['n']++;
            $byCategory[$category]['top3'] += $top3 ? 1 : 0;
            $byCategory[$category]['recall5'] += $recall5 ? 1 : 0;
            $byCategory[$category]['mrr'] += $rr;

            $rows[] = [
                'id' => $item['id'],
                'category' => $category,
                'rank' => $rank,
                'score' => $top[0]['score'] ?? 0.0,
                'hit_score' => $rank > 0 ? ($top[$rank - 1]['score'] ?? 0.0) : 0.0,
                'top' => $topIds,
                'expected' => $expected,
                'question' => $item['question'],
            ];
        }

        $n = max(1, count($items));
        ksort($byCategory);

        return [
            'n' => count($items),
            'top3' => $top3Hits / $n,
            'recall5' => $recall5Hits / $n,
            'mrr' => $rrSum / $n,
            'rows' => $rows,
            'by_category' => $byCategory,
        ];
    }

    /**
     * @param  array{n: int, top3: float, recall5: float, mrr: float, rows: list<array<string, mixed>>, by_category: array<string, array{n: int, top3: int, recall5: int, mrr: float}>}  $m
     */
    private function writeReport(array $m): void
    {
        $today = date('d-m-Y');
        $lines = [];
        $lines[] = '';
        $lines[] = "## Замер H3766 B2 — 100-вопросный набор ({$today})";
        $lines[] = '';
        $lines[] = '**Model:** Opus 5 (`claude-opus-5`)';
        $lines[] = '**Fixture:** `tests/fixtures/faq_rag_eval.json` v2 — 100 вопросов (20 рукописных H2448 + 80 добытых из `telegram_support_messages`)';
        $lines[] = '**Retriever:** Okapi BM25 (pure PHP), top-5';
        $lines[] = '';
        $lines[] = sprintf(
            '| Метрика | Значение |'."\n".'|---|---|'."\n".'| top-3 hit rate | **%.1f%%** |'."\n".'| recall@5 | **%.1f%%** |'."\n".'| MRR | **%.3f** |',
            $m['top3'] * 100,
            $m['recall5'] * 100,
            $m['mrr'],
        );
        $lines[] = '';
        $lines[] = '| Категория | N | top-3 | recall@5 | MRR |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($m['by_category'] as $cat => $c) {
            $lines[] = sprintf(
                '| %s | %d | %.0f%% | %.0f%% | %.3f |',
                $cat,
                $c['n'],
                $c['n'] > 0 ? $c['top3'] / $c['n'] * 100 : 0,
                $c['n'] > 0 ? $c['recall5'] / $c['n'] * 100 : 0,
                $c['n'] > 0 ? $c['mrr'] / $c['n'] : 0,
            );
        }
        $lines[] = '';
        $lines[] = '| ID | Кат. | Ранг | Вопрос | top-5 chunk_ids | Ожидалось |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach ($m['rows'] as $r) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | `%s` | `%s` |',
                $r['id'],
                $r['category'],
                $r['rank'] > 0 ? (string) $r['rank'] : '—',
                str_replace('|', '\\|', (string) $r['question']),
                implode('`, `', $r['top']),
                implode('`, `', $r['expected']),
            );
        }
        $lines[] = '';
        $lines[] = '_Dr. Mārcis Gasūns_';
        $lines[] = '';

        $path = base_path('docs/FAQ_RAG_EVAL_H2448.md');
        file_put_contents($path, rtrim((string) file_get_contents($path))."\n".implode("\n", $lines));
    }
}
