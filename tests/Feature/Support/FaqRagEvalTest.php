<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\HybridRetriever;
use App\Services\Support\Faq\NullEmbeddingProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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
    /** Ратчет H3766 — измеренный базлайн; каждая правка B3 поднимает его (замер 31-08-2026). */
    private const GATE_TOP3 = 0.77;

    private const GATE_RECALL5 = 0.83;

    private const GATE_MRR = 0.71;

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
     * H4001 (пол плана): когда dense-нога недоступна, гибрид обязан давать
     * БАЙТ-В-БАЙТ ранжирование BM25 на ОБОИХ наборах. CI-безопасный гейт.
     */
    public function test_hybrid_matches_bm25_when_dense_leg_unavailable(): void
    {
        config([
            'features.faq_rag_suggester' => true,
            'features.faq_hybrid_retrieval' => true,
            'knowledge.driver' => 'ollama',
        ]);
        $this->app->instance(EmbeddingProvider::class, new NullEmbeddingProvider);

        $bm25 = app(Bm25FaqRetriever::class);
        $hybrid = app(HybridRetriever::class);
        $this->assertTrue($hybrid->isEnabled());

        foreach (['tests/fixtures/faq_rag_eval.json', 'tests/fixtures/faq_rag_eval_fresh.json'] as $fixtureRel) {
            $fixturePath = base_path($fixtureRel);
            $this->assertFileExists($fixturePath);
            $items = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR)['items'];

            foreach ($items as $item) {
                $query = (string) $item['question'];
                $floor = array_map(static fn (array $h): array => [
                    'chunk_id' => $h['chunk_id'], 'score' => $h['score'],
                ], $bm25->retrieve($query, 5));
                $hybridRanking = array_map(static fn (array $h): array => [
                    'chunk_id' => $h['chunk_id'], 'score' => $h['score'],
                ], $hybrid->retrieve($query, 5));

                $this->assertSame($floor, $hybridRanking, "dense leg unavailable: ranking must equal BM25 ({$fixtureRel})");
            }
        }
    }

    /**
     * H4001 live-гейт: гибрид не ниже BM25 ни на одном наборе + p95 латентность
     * ≤ 2 c через туннель. Требует живого туннеля и KNOWLEDGE_LIVE_EVAL=1
     * (запускается вручную в рамках live-прогона, в CI пропускается).
     */
    public function test_live_hybrid_matches_or_beats_bm25_on_both_sets(): void
    {
        // Живые координаты читаем через getenv напрямую: внутри phpunit-процесса
        // shell-env не всегда доезжает до env()-репозитория Laravel (проверено
        // 03-09-2026: env()-путь давал локальный 11434 вместо туннеля), а живой
        // прогон обязан идти в НАЗВАННЫЙ узел. Таймаут для live-прогона выше
        // DM-дефолта: батч 16 × 1024 dims через два ssh-хопа.
        if (! filter_var((string) getenv('KNOWLEDGE_LIVE_EVAL'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('KNOWLEDGE_LIVE_EVAL=1 + live tunnel required (H4001 live pass)');
        }
        config([
            'knowledge.driver' => getenv('KNOWLEDGE_EMBEDDING_DRIVER') ?: 'ollama',
            'knowledge.base_url' => getenv('KNOWLEDGE_OLLAMA_BASE_URL') ?: 'http://127.0.0.1:11434',
            'knowledge.timeout' => (int) (getenv('KNOWLEDGE_REQUEST_TIMEOUT') ?: 30),
            'knowledge.dimensions' => (int) (getenv('KNOWLEDGE_EMBEDDING_DIMENSIONS') ?: 1024),
            'knowledge.embedding_model' => getenv('KNOWLEDGE_EMBEDDING_MODEL') ?: 'bge-m3:latest',
        ]);

        $migrateExit = Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_09_03_110000_create_knowledge_chunks_table.php',
            '--force' => true,
        ]);
        $this->assertSame(0, $migrateExit, 'migrate failed: '.Artisan::output());

        Http::allowStrayRequests();
        // Artisan::call, не $this->artisan(): PendingCommand молча проглатывает
        // sync-dispatch джобы этого прохода (проверено 03-09-2026: exit 0,
        // ноль строк при рабочем узле) — живой смоук обязан идти реальным
        // CLI-путём, как его запустил бы человек.
        $indexExit = Artisan::call('knowledge:index', ['--force' => true, '--sync' => true]);
        $indexOutput = Artisan::output();
        $this->assertSame(0, $indexExit, 'knowledge:index failed: '.$indexOutput);
        $this->assertGreaterThan(0, KnowledgeChunk::count(), 'live index must produce rows: '.$indexOutput);

        config(['features.faq_rag_suggester' => true, 'features.faq_hybrid_retrieval' => true]);

        $parser = app(FaqCorpusParser::class);
        $knownIds = array_flip(array_map(static fn ($c) => $c->chunkId, $parser->chunks()));
        $bm25 = app(Bm25FaqRetriever::class);
        $hybrid = app(HybridRetriever::class);

        $report = [];
        foreach (['80Q' => 'tests/fixtures/faq_rag_eval.json', 'fresh' => 'tests/fixtures/faq_rag_eval_fresh.json'] as $name => $fixtureRel) {
            $items = json_decode((string) file_get_contents(base_path($fixtureRel)), true, 512, JSON_THROW_ON_ERROR)['items'];

            $mBm25 = $this->measureRetriever($bm25, $items, $knownIds);
            $mHybrid = $this->measureRetriever($hybrid, $items, $knownIds);

            $this->assertGreaterThanOrEqual(
                $mBm25['recall5'],
                $mHybrid['recall5'],
                "H4001 defect: hybrid recall@5 below BM25 floor on {$name}",
            );
            $this->assertGreaterThanOrEqual(
                $mBm25['mrr'] - 0.001,
                $mHybrid['mrr'],
                "H4001 defect: hybrid MRR below BM25 floor on {$name}",
            );
            $this->assertLessThanOrEqual(2.0, $mHybrid['p95_ms'] / 1000.0, "p95 retrieval latency above 2 s on {$name}");

            $report[] = ['name' => $name, 'bm25' => $mBm25, 'hybrid' => $mHybrid];
        }

        if (filter_var(env('FAQ_RAG_EVAL_WRITE', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->writeLiveReport($report);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $knownIds
     * @return array{n: int, top3: float, recall5: float, mrr: float, rows: list<array<string, mixed>>, by_category: array<string, array{n: int, top3: int, recall5: int, mrr: float}>}
     */
    private function measure(array $items, Bm25FaqRetriever $retriever, array $knownIds): array
    {
        return $this->measureRetriever($retriever, $items, $knownIds);
    }

    /**
     * H4001: обобщённый замер — принимает и BM25, и HybridRetriever (дроп-ин
     * шейпа), плюс p95 латентность retrieve() на вопрос.
     *
     * @param  Bm25FaqRetriever|HybridRetriever  $retriever
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $knownIds
     * @return array{n: int, top3: float, recall5: float, mrr: float, p95_ms: float, rows: list<array<string, mixed>>, by_category: array<string, array{n: int, top3: int, recall5: int, mrr: float}>}
     */
    private function measureRetriever(object $retriever, array $items, array $knownIds): array
    {
        $top3Hits = 0;
        $recall5Hits = 0;
        $rrSum = 0.0;
        $rows = [];
        $byCategory = [];
        $latencies = [];

        foreach ($items as $item) {
            /** @var list<string> $expected */
            $expected = $item['expected_chunk_ids'] ?? [];
            foreach ($expected as $eid) {
                $this->assertArrayHasKey($eid, $knownIds, "expected chunk_id missing from corpus: {$eid}");
            }

            $startedAt = microtime(true);
            $top = $retriever->retrieve((string) $item['question'], 5);
            $latencies[] = (microtime(true) - $startedAt) * 1000.0;

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
        sort($latencies);
        $p95Index = (int) floor(0.95 * max(0, count($latencies) - 1));

        return [
            'n' => count($items),
            'top3' => $top3Hits / $n,
            'recall5' => $recall5Hits / $n,
            'mrr' => $rrSum / $n,
            'p95_ms' => $latencies === [] ? 0.0 : (float) $latencies[$p95Index],
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

    /**
     * H4001: dated live-раздел — BM25 против гибрида на обоих наборах,
     * p95 латентность через туннель. Пишется только при FAQ_RAG_EVAL_WRITE=1.
     *
     * @param  list<array{name: string, bm25: array<string, mixed>, hybrid: array<string, mixed>}>  $report
     */
    private function writeLiveReport(array $report): void
    {
        $lines = [];
        $lines[] = '';
        $lines[] = '## Замер H4001 (Wave 3) — BM25 против HybridRetriever, live-туннель ('.date('d-m-Y').')';
        $lines[] = '';
        $lines[] = '**Model:** OxAlpha (opencode, GLM) — выполнение H4001';
        $lines[] = '**Dense leg:** `bge-m3` через sshd-туннель на GPU-узле (`config/knowledge.php`), knowledge:index --force --sync перед замером';
        $lines[] = '';
        $lines[] = '| Набор | N | Ретривер | top-3 | recall@5 | MRR | p95, мс |';
        $lines[] = '|---|---|---|---|---|---|---|';
        foreach ($report as $r) {
            foreach (['bm25' => 'BM25 (пол)', 'hybrid' => 'Hybrid (RRF)'] as $key => $label) {
                $m = $r[$key];
                $lines[] = sprintf(
                    '| %s | %d | %s | %.1f%% | %.1f%% | %.3f | %.0f |',
                    $r['name'],
                    $m['n'],
                    $label,
                    $m['top3'] * 100,
                    $m['recall5'] * 100,
                    $m['mrr'],
                    $m['p95_ms'],
                );
            }
        }
        $lines[] = '';
        $lines[] = '_Dr. Mārcis Gasūns_';
        $lines[] = '';

        $path = base_path('docs/FAQ_RAG_EVAL_H2448.md');
        file_put_contents($path, rtrim((string) file_get_contents($path))."\n".implode("\n", $lines));
    }
}
