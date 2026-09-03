<?php

declare(strict_types=1);

namespace App\Services\Support\Knowledge;

/**
 * H3234 stage 3 — embedding source for HybridRetriever / IndexKnowledgeChunkJob.
 * Every implementation MUST fail closed: return null, never throw, so a dead
 * tunnel degrades retrieval to BM25-only instead of breaking the caller
 * (H3234 acceptance: "tunnel-down degrades to StudentSelfService, not DeepSeek",
 * the same fail-closed shape applied to embeddings).
 */
interface EmbeddingProvider
{
    /**
     * @return list<float>|null null on any failure (timeout, non-200, empty text).
     */
    public function embed(string $text): ?array;

    public function modelName(): string;
}
