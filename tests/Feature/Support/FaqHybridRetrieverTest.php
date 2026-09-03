<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\FaqChunk;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\HybridRetriever;
use App\Services\Support\Faq\KnowledgeVectors;
use App\Services\Support\Faq\NullEmbeddingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * H4001 — HybridRetriever: шейп-паритет с Bm25FaqRetriever, BM25-пол при
 * недоступной dense-ноге, RRF-слияние при доступной, денежный порог в домене
 * BM25.
 */
class FaqHybridRetrieverTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpFaq;

    private const CORPUS = <<<'MD'
# FAQ test

## Политика и поддержка

### Оплата: блоки (поблочно vs целиком)
Оплата курса идёт поблочно или целиком за весь курс.

### Записи уроков и пропуски
Запись каждого занятия выкладывается в закрытый чат курса.

## Техподдержка

### Не работает вход в личный кабинет
Вход в кабинет: восстановление пароля по ссылке из письма.
MD;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpFaq = storage_path('framework/testing/faq_hybrid_h4001.md');
        File::ensureDirectoryExists(dirname($this->tmpFaq));
        File::put($this->tmpFaq, self::CORPUS);

        config([
            'support.faq_rag.path' => $this->tmpFaq,
            'support.faq_rag.min_score' => 0.5,
            'support.faq_rag.top_k' => 2,
            'features.faq_rag_suggester' => true,
            'features.faq_hybrid_retrieval' => false,
            'knowledge.driver' => 'ollama',
            'knowledge.dimensions' => 4,
            'knowledge.embedding_model' => 'test-model',
            'knowledge.fusion.k' => 60,
            'knowledge.fusion.depth' => 20,
        ]);
    }

    public function test_flag_off_matches_bm25_ranking_and_never_calls_provider(): void
    {
        $fake = new FakeEmbeddingProvider(default: [1.0, 0.0, 0.0, 0.0]);
        $this->app->instance(EmbeddingProvider::class, $fake);

        $hybrid = app(HybridRetriever::class);
        $bm25 = app(Bm25FaqRetriever::class);

        $this->assertFalse($hybrid->isEnabled());

        $query = 'как оплатить курс поблочно';
        $this->assertSame(
            $this->ranking($bm25->retrieve($query, 2)),
            $this->ranking($hybrid->retrieve($query, 2)),
            'flag OFF: ranking must be BM25-identical, provider untouched',
        );
        $this->assertSame(0, $fake->calls, 'flag OFF: dense leg must not be called');
    }

    public function test_null_provider_yields_bm25_ranking_plus_one_warning(): void
    {
        Log::spy();

        $this->app->instance(EmbeddingProvider::class, new NullEmbeddingProvider);
        config(['features.faq_hybrid_retrieval' => true]);

        $hybrid = app(HybridRetriever::class);
        $bm25 = app(Bm25FaqRetriever::class);

        $this->assertTrue($hybrid->isEnabled());

        $query = 'запись урока где';
        $this->assertSame(
            $this->ranking($bm25->retrieve($query, 2)),
            $this->ranking($hybrid->retrieve($query, 2)),
            'empty embedding = dense leg unavailable: BM25 floor unchanged',
        );
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_no_indexed_rows_yields_bm25_ranking(): void
    {
        config(['features.faq_hybrid_retrieval' => true]);
        $this->app->instance(
            EmbeddingProvider::class,
            new FakeEmbeddingProvider(default: [1.0, 0.0, 0.0, 0.0]),
        );

        $hybrid = app(HybridRetriever::class);
        $bm25 = app(Bm25FaqRetriever::class);

        $query = 'вход в кабинет не работает';
        $this->assertSame(
            $this->ranking($bm25->retrieve($query, 2)),
            $this->ranking($hybrid->retrieve($query, 2)),
        );
    }

    public function test_fusion_promotes_dual_leg_hit_and_keeps_shape(): void
    {
        config(['features.faq_hybrid_retrieval' => true]);

        $chunks = $this->chunkMap();
        $this->assertCount(3, $chunks, 'fixture corpus must parse into 3 h3-chunks');

        // Плот-план: запрос про оплату+вход. BM25 ставит оплату первой (два
        // терма), кабинет вторым (один). Dense: кабинет коллинеарен запросу
        // (dense rank 1), записи — под углом (rank 2), оплата НЕ
        // проиндексирована (нет вектора). RRF: кабинет = 1/62 + 1/61, оплата =
        // 1/61 — кабинет обгоняет. Это ровно тот случай, ради которого гибрид
        // существует: dense-ранг 1 + непустой sparse-ранг.
        $query = 'оплата курса вход';
        KnowledgeChunk::create([
            'faq_chunk_id' => 'техподдержка/не-работает-вход-в-личный-кабинет',
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => 'x',
        ]);
        KnowledgeChunk::create([
            'faq_chunk_id' => 'политика-и-поддержка/записи-уроков-и-пропуски',
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([0.7071, 0.7071, 0.0, 0.0]),
            'content_hash' => 'y',
        ]);

        $this->app->instance(
            EmbeddingProvider::class,
            new FakeEmbeddingProvider(vectors: [$query => [1.0, 0.0, 0.0, 0.0]]),
        );

        $hybrid = app(HybridRetriever::class);
        $bm25 = app(Bm25FaqRetriever::class);

        $bm25Ranking = $this->ranking($bm25->retrieve($query, 2));
        $this->assertSame(
            'политика-и-поддержка/оплата-блоки-поблочно-vs-целиком',
            $bm25Ranking[0]['chunk_id'],
            'precondition: BM25 puts the payment chunk first',
        );

        $hybridHits = $hybrid->retrieve($query, 2);
        $this->assertSame(
            'техподдержка/не-работает-вход-в-личный-кабинет',
            $hybridHits[0]['chunk_id'],
            'fusion must lift the dual-leg chunk above the sparse-only winner',
        );

        foreach ($hybridHits as $hit) {
            foreach (['chunk_id', 'title', 'heading_path', 'snippet', 'source', 'score', 'bm25_score'] as $key) {
                $this->assertArrayHasKey($key, $hit, "citation shape parity: missing {$key}");
            }
            $this->assertLessThan(0.1, $hit['score'], 'RRF scores live near 1/k, not in the BM25 scale');
        }
        $this->assertGreaterThan(0.0, $hybridHits[0]['bm25_score'], 'dual-leg winner keeps its BM25 leg score');
    }

    public function test_dense_only_hit_enters_fusion_when_bm25_is_empty(): void
    {
        config(['features.faq_hybrid_retrieval' => true]);

        KnowledgeChunk::create([
            'faq_chunk_id' => 'техподдержка/не-работает-вход-в-личный-кабинет',
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => 'x',
        ]);

        // Токенов после стоп-слов BM25 не остаётся → sparse-нога пуста.
        $query = 'и что';
        $this->app->instance(
            EmbeddingProvider::class,
            new FakeEmbeddingProvider(vectors: [$query => [1.0, 0.0, 0.0, 0.0]]),
        );

        $hits = app(HybridRetriever::class)->retrieve($query, 2);
        $this->assertNotEmpty($hits, 'dense-only ranking must still surface indexed chunks');
        $this->assertSame('техподдержка/не-работает-вход-в-личный-кабинет', $hits[0]['chunk_id']);
    }

    public function test_money_threshold_reads_bm25_domain_not_rrf(): void
    {
        config(['support.faq_rag.min_score' => 1.5]);

        $hybrid = app(HybridRetriever::class);

        $this->assertTrue($hybrid->passesThreshold([['score' => 0.02, 'bm25_score' => 2.0]]));
        $this->assertFalse($hybrid->passesThreshold([['score' => 0.02, 'bm25_score' => 0.5]]));
        $this->assertFalse($hybrid->passesThreshold([]));
    }

    /**
     * Ранжирование в нейтральной форме: [(chunk_id, score)] — устойчиво к
     * добавочному ключу bm25_score у гибрида.
     *
     * @param  list<array{chunk_id?: string, score?: float}>  $hits
     * @return list<array{chunk_id: string, score: float}>
     */
    private function ranking(array $hits): array
    {
        return array_map(static fn (array $h): array => [
            'chunk_id' => (string) ($h['chunk_id'] ?? ''),
            'score' => (float) ($h['score'] ?? 0.0),
        ], $hits);
    }

    /** @return array<string, FaqChunk> */
    private function chunkMap(): array
    {
        $chunks = [];
        foreach (app(FaqCorpusParser::class)->chunks() as $chunk) {
            $chunks[$chunk->chunkId] = $chunk;
        }

        return $chunks;
    }
}
