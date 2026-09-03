<?php

declare(strict_types=1);

namespace App\Services\Support\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\Bm25FaqRetriever;

/**
 * H3234 stage 3 — BM25 candidate generation (existing Bm25FaqRetriever, H2448)
 * fused with vector cosine similarity from indexed `knowledge_chunks`, via
 * Reciprocal Rank Fusion (k=60, the usual RRF default).
 *
 * Degrades to plain BM25 order whenever there is nothing to fuse with: no
 * indexed embeddings yet, or the query itself could not be embedded (tunnel
 * down / NullEmbeddingProvider). This is deliberate — it is the same
 * fail-closed shape as the rest of the support stack (H3234 acceptance:
 * "tunnel-down degrades to StudentSelfService, not DeepSeek") and it is also
 * why `knowledge:eval` reads "hybrid == BM25" honestly until
 * IndexKnowledgeChunkJob has actually populated embeddings for the corpus.
 */
final class HybridRetriever
{
    private const RRF_K = 60;

    // Candidate pool is wider than topK so re-ranking has something to work
    // with; small corpus (FAQ ~120 chunks today) makes this cheap.
    private const CANDIDATE_MULTIPLIER = 4;

    public function __construct(
        private readonly Bm25FaqRetriever $bm25,
        private readonly EmbeddingProvider $embeddings,
        private readonly string $source = 'faq',
    ) {}

    /**
     * @return list<array{chunk_id: string, title: string, heading_path: list<string>, snippet: string, source: string, score: float}>
     */
    public function retrieve(string $query, ?int $topK = null, ?string $path = null): array
    {
        $topK ??= (int) config('support.faq_rag.top_k', 3);
        $topK = max(1, $topK);

        $bm25Hits = $this->bm25->retrieveChunks($query, $topK * self::CANDIDATE_MULTIPLIER, $path);
        if ($bm25Hits === []) {
            return [];
        }

        $bm25Rank = [];
        foreach (array_values($bm25Hits) as $i => $hit) {
            $bm25Rank[$hit['chunk']->chunkId] = $i + 1;
        }

        $indexed = $this->indexedEmbeddings(array_keys($bm25Rank));
        if ($indexed === []) {
            // Nothing embedded for these candidates yet — pure BM25 order.
            return $this->citeTopK($bm25Hits, $topK);
        }

        $queryVector = $this->embeddings->embed($query);
        if ($queryVector === null) {
            // Tunnel down / provider unavailable this call — pure BM25 order.
            return $this->citeTopK($bm25Hits, $topK);
        }

        $vectorRank = $this->rankByCosine($queryVector, $indexed);

        $fused = [];
        foreach ($bm25Hits as $hit) {
            $chunkId = $hit['chunk']->chunkId;
            $rrf = 1.0 / (self::RRF_K + $bm25Rank[$chunkId]);
            if (isset($vectorRank[$chunkId])) {
                $rrf += 1.0 / (self::RRF_K + $vectorRank[$chunkId]);
            }
            $fused[] = ['chunk' => $hit['chunk'], 'score' => $rrf];
        }

        usort($fused, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return $this->citeTopK($fused, $topK);
    }

    /**
     * @param  list<array{chunk: \App\Services\Support\Faq\FaqChunk, score: float}>  $scored
     * @return list<array{chunk_id: string, title: string, heading_path: list<string>, snippet: string, source: string, score: float}>
     */
    private function citeTopK(array $scored, int $topK): array
    {
        $out = [];
        foreach (array_slice($scored, 0, $topK) as $s) {
            $citation = $s['chunk']->toCitation();
            $citation['score'] = round($s['score'], 6);
            $out[] = $citation;
        }

        return $out;
    }

    /**
     * @param  list<string>  $chunkIds
     * @return array<string, list<float>> chunk_id => vector
     */
    private function indexedEmbeddings(array $chunkIds): array
    {
        if ($chunkIds === []) {
            return [];
        }

        $rows = KnowledgeChunk::query()
            ->where('source', $this->source)
            ->whereIn('chunk_id', $chunkIds)
            ->whereNotNull('embedding')
            ->get(['chunk_id', 'embedding']);

        $out = [];
        foreach ($rows as $row) {
            $vector = $row->embeddingVector();
            if ($vector !== null) {
                $out[$row->chunk_id] = $vector;
            }
        }

        return $out;
    }

    /**
     * @param  list<float>  $query
     * @param  array<string, list<float>>  $candidates  chunk_id => vector
     * @return array<string, int> chunk_id => 1-based rank by cosine similarity, best first
     */
    private function rankByCosine(array $query, array $candidates): array
    {
        $scored = [];
        foreach ($candidates as $chunkId => $vector) {
            $scored[$chunkId] = self::cosine($query, $vector);
        }
        arsort($scored, SORT_NUMERIC);

        $rank = [];
        $i = 0;
        foreach (array_keys($scored) as $chunkId) {
            $rank[$chunkId] = ++$i;
        }

        return $rank;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function cosine(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
