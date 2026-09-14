<?php

declare(strict_types=1);

namespace App\Services\Support\StudentAgent\Tools;

use App\Models\User;
use App\Services\Support\Faq\Bm25FaqRetriever;

/**
 * Job 3/3 (H3231): cabinet FAQ lookup. Reuses the H2448 BM25 retriever and
 * faq.md corpus as-is — no new RAG pipeline, no LLM synthesis on top; the
 * tool returns cited snippets straight from {@see Bm25FaqRetriever}. Gated
 * on the existing features.faq_rag_suggester flag so this does not open a
 * second, ungated path into a feature that is deliberately still OFF.
 */
final class CabinetFaqTool implements StudentAgentTool
{
    public function __construct(private readonly Bm25FaqRetriever $retriever) {}

    public function name(): string
    {
        return 'cabinet_faq';
    }

    public function isIrreversible(): bool
    {
        return false;
    }

    public function run(User $user, array $params): array
    {
        $query = trim((string) ($params['query'] ?? ''));

        if ($query === '') {
            return ['ok' => false, 'reason' => 'empty_query'];
        }

        if (! $this->retriever->isEnabled()) {
            return ['ok' => false, 'reason' => 'faq_rag_disabled'];
        }

        $hits = $this->retriever->retrieve($query);

        return ['ok' => true, 'data' => ['query' => $query, 'hits' => $hits]];
    }
}
