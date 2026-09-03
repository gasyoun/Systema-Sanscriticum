<?php

declare(strict_types=1);

namespace App\Services\Support\Knowledge;

/**
 * H3234 stage 3 — safe default. Never touches the network, always returns
 * null, so anything wired to EmbeddingProvider degrades to pure BM25 with
 * no code path change. Bound whenever features.knowledge_hybrid_retrieval
 * is OFF, or when OllamaEmbeddingProvider itself gives up.
 */
final class NullEmbeddingProvider implements EmbeddingProvider
{
    public function embed(string $text): ?array
    {
        return null;
    }

    public function modelName(): string
    {
        return 'null';
    }
}
