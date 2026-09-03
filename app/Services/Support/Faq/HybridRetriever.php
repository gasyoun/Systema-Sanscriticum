<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

use App\Models\KnowledgeChunk;

/**
 * H4001 (Wave 3) — BM25 ∪ dense с reciprocal-rank fusion, дроп-ин уровня
 * {@see Bm25FaqRetriever}: те же публичные подписи, тот же шейп результата.
 *
 * Пол по контракту плана: BM25 — пол, fusion ниже BM25 на eval-наборах —
 * дефект, а не результат тюнинга. Когда dense-нога недоступна (флаг OFF,
 * пустой embedding от провайдера, пустая knowledge_chunks) ранжирование
 * БАЙТ-В-БАЙТ равно BM25 — dense-нога даже не зовётся, туннель не попадает
 * в request-path.
 *
 * Шкалы скоров у ног разные (BM25 ~0…20, RRF ~0…0.033), поэтому денежный
 * порог честно считается в домене BM25: каждая цитата несёт bm25_score,
 * passesThreshold() читает его, а не RRF-скор. Слияние — score(d) =
 * Σ 1/(k + rank_i(d)) по config('knowledge.fusion').
 */
final class HybridRetriever
{
    public function __construct(
        private readonly Bm25FaqRetriever $bm25,
        private readonly EmbeddingProvider $embeddings,
        private readonly FaqCorpusParser $parser,
    ) {}

    /**
     * Гейт lane'а — ТОЛЬКО флаг lane'а (features.faq_rag_suggester), ровно как
     * у Bm25FaqRetriever: дроп-ин обязан вести себя идентично и здесь, иначе
     * потребители при выключенном faq_hybrid_retrieval теряют RAG-путь.
     * Флаг features.faq_hybrid_retrieval управляет только участием dense-ноги
     * (см. retrieveChunks) — ON добавляет fusion, OFF = байт-в-байт BM25.
     */
    public function isEnabled(): bool
    {
        return $this->bm25->isEnabled();
    }

    /**
     * @return list<array{chunk_id: string, title: string, heading_path: list<string>, snippet: string, source: string, score: float, bm25_score: float}>
     */
    public function retrieve(string $query, ?int $topK = null, ?string $path = null): array
    {
        $out = [];
        foreach ($this->retrieveChunks($query, $topK, $path) as $scored) {
            $citation = $scored['chunk']->toCitation();
            $citation['score'] = round($scored['score'], 4);
            $citation['bm25_score'] = round($scored['bm25_score'], 4);
            $out[] = $citation;
        }

        return $out;
    }

    /**
     * @return list<array{chunk: FaqChunk, score: float, bm25_score: float}>
     */
    public function retrieveChunks(string $query, ?int $topK = null, ?string $path = null): array
    {
        $topK ??= (int) config('support.faq_rag.top_k', 3);
        $topK = max(1, $topK);

        $sparse = $this->bm25->retrieveChunks($query, $this->fusionDepth(), $path);

        if (! $this->denseEnabled()) {
            return $this->asBm25Floor(array_slice($sparse, 0, $topK));
        }

        $dense = $this->denseLeg($query, $path);
        if ($dense === []) {
            // Пустой embedding = «dense-нога недоступна» — BM25-пол без изменений.
            return $this->asBm25Floor(array_slice($sparse, 0, $topK));
        }

        return array_slice($this->fuse($sparse, $dense), 0, $topK);
    }

    /**
     * Dense-нога зовётся только когда hybrid-флаг включён — OFF означает,
     * что туннель вообще не попадает в request-path.
     */
    private function denseEnabled(): bool
    {
        return (bool) config('features.faq_hybrid_retrieval', false);
    }

    /**
     * Пол-путь: тот же шейп, что у fusion (bm25_score = BM25-скор).
     *
     * @param  list<array{chunk: FaqChunk, score: float}>  $rows
     * @return list<array{chunk: FaqChunk, score: float, bm25_score: float}>
     */
    private function asBm25Floor(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'chunk' => $row['chunk'],
                'score' => $row['score'],
                'bm25_score' => $row['score'],
            ],
            $rows,
        );
    }

    /**
     * Деньги/политика (категория D) — порог в домене BM25: RRF-скор на другой
     * шкале и молча занизил бы проход через support.faq_rag.min_score.
     *
     * @param  list<array{score?: float, bm25_score?: float}>  $hits
     */
    public function passesThreshold(array $hits): bool
    {
        if ($hits === []) {
            return false;
        }

        $min = (float) config('support.faq_rag.min_score', 1.5);
        $best = (float) ($hits[0]['bm25_score'] ?? $hits[0]['score'] ?? 0.0);

        return $best >= $min;
    }

    /**
     * Категории, которым нельзя изобретать политику без FAQ-хита — как у BM25.
     *
     * @return list<string>
     */
    public function moneyPolicyCategories(): array
    {
        return $this->bm25->moneyPolicyCategories();
    }

    /**
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        return $this->bm25->tokenize($text);
    }

    /**
     * Dense-нога: [(chunkId, cosine)] по текущему корпусу с проиндексированным
     * вектором. Пусто, если embedding запроса пуст, векторов нет или корпус
     * не распарсился.
     *
     * @return array<string, array{chunk: FaqChunk, score: float}>
     */
    private function denseLeg(string $query, ?string $path): array
    {
        $queryVector = $this->embeddings->embed($query);
        if ($queryVector === []) {
            return [];
        }

        $model = (string) config('knowledge.embedding_model', 'bge-m3');
        $dims = (int) config('knowledge.dimensions', 1024);

        $rows = KnowledgeChunk::query()
            ->where('model', $model)
            ->where('dims', $dims)
            ->pluck('embedding', 'faq_chunk_id');
        if ($rows->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($this->parser->chunks($path) as $chunk) {
            $binary = $rows[$chunk->chunkId] ?? null;
            if ($binary === null) {
                continue;
            }
            $score = KnowledgeVectors::cosine($queryVector, KnowledgeVectors::unpack($binary));
            $out[$chunk->chunkId] = ['chunk' => $chunk, 'score' => $score];
        }

        return $out;
    }

    /**
     * @param  list<array{chunk: FaqChunk, score: float, bm25_score: float}>  $sparse
     * @param  array<string, array{chunk: FaqChunk, score: float}>  $dense
     * @return list<array{chunk: FaqChunk, score: float, bm25_score: float}>
     */
    private function fuse(array $sparse, array $dense): array
    {
        $k = max(1, (int) config('knowledge.fusion.k', 60));
        // Weighted RRF: спарс-нога — пол по контракту, поэтому её вес ≥ dense;
        // равные веса на свежем наборе роняли MRR ниже BM25 (live-замер H4001).
        $wSparse = max(0.1, (float) config('knowledge.fusion.weight_sparse', 1.0));
        $wDense = max(0.1, (float) config('knowledge.fusion.weight_dense', 0.6));

        $fused = [];
        $bm25Scores = [];
        foreach (array_values($sparse) as $rank => $hit) {
            $id = $hit['chunk']->chunkId;
            $fused[$id] = ($fused[$id] ?? 0.0) + $wSparse / ($k + $rank + 1);
            $bm25Scores[$id] = (float) $hit['score'];
        }

        $denseRanking = [];
        foreach ($dense as $hit) {
            $denseRanking[$hit['chunk']->chunkId] = (float) $hit['score'];
        }
        arsort($denseRanking, SORT_NUMERIC);

        $denseRank = 0;
        foreach (array_keys($denseRanking) as $id) {
            $fused[$id] = ($fused[$id] ?? 0.0) + $wDense / ($k + $denseRank + 1);
            $bm25Scores[$id] ??= 0.0;
            $denseRank++;
        }

        $chunksById = [];
        foreach ($sparse as $hit) {
            $chunksById[$hit['chunk']->chunkId] = $hit['chunk'];
        }
        foreach ($dense as $hit) {
            $chunksById[$hit['chunk']->chunkId] ??= $hit['chunk'];
        }

        $out = [];
        foreach ($fused as $id => $score) {
            $out[] = [
                'chunk' => $chunksById[$id],
                'score' => $score,
                'bm25_score' => $bm25Scores[$id],
            ];
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return strcmp($a['chunk']->chunkId, $b['chunk']->chunkId);
            }

            return $a['score'] < $b['score'] ? 1 : -1;
        });

        return $out;
    }

    private function fusionDepth(): int
    {
        return max(1, (int) config('knowledge.fusion.depth', 20));
    }
}
