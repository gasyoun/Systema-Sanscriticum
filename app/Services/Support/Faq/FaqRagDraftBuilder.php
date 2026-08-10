<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

/**
 * Build a curator-facing draft from retrieved FAQ chunks (H2448).
 * Never invents prices — steers to live catalogue for amounts.
 */
final class FaqRagDraftBuilder
{
    /**
     * @param  list<array{chunk_id: string, title: string, heading_path?: list<string>, snippet: string, source?: string, score?: float}>  $hits
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}
     */
    public function fromHits(string $question, array $hits, string $category): array
    {
        $lines = [];
        $lines[] = 'По базе знаний (FAQ):';
        $lines[] = '';

        foreach (array_slice($hits, 0, 3) as $i => $hit) {
            $n = $i + 1;
            $title = (string) ($hit['title'] ?? $hit['chunk_id']);
            $snippet = trim((string) ($hit['snippet'] ?? ''));
            $lines[] = "{$n}. «{$title}»";
            if ($snippet !== '') {
                $lines[] = $snippet;
            }
            $lines[] = '';
        }

        if ($category === 'D') {
            $lines[] = 'Важно: точные цены и даты курсов — только из живого каталога на сайте (не из FAQ).';
            $lines[] = 'Черновик для куратора; бот ничего не отправил студенту.';
        } else {
            $lines[] = 'Черновик для куратора по разделам FAQ; при необходимости уточните персональные детали из LMS.';
        }

        $best = (float) ($hits[0]['score'] ?? 0.0);
        // Soft map BM25 score → 0.55–0.92 confidence band (curator still reviews).
        $confidence = min(0.92, max(0.55, 0.5 + min($best, 8.0) / 20.0));

        $citations = array_map(static function (array $hit): array {
            return [
                'chunk_id' => (string) ($hit['chunk_id'] ?? ''),
                'title' => (string) ($hit['title'] ?? ''),
                'heading_path' => $hit['heading_path'] ?? [],
                'snippet' => (string) ($hit['snippet'] ?? ''),
                'source' => (string) ($hit['source'] ?? 'faq.md'),
                'score' => isset($hit['score']) ? (float) $hit['score'] : null,
            ];
        }, $hits);

        return [
            'draft' => trim(implode("\n", $lines)),
            'facts' => [
                'source' => 'faq_rag',
                'faq_citations' => $citations,
                'question_preview' => mb_substr($question, 0, 200),
                'category' => $category,
            ],
            'confidence' => round($confidence, 3),
        ];
    }

    /**
     * Attach citations to an existing LMS/template/LLM draft without changing text.
     *
     * @param  array{draft: string, facts: array<string, mixed>, confidence: float}  $resolved
     * @param  list<array<string, mixed>>  $hits
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}
     */
    public function attachCitations(array $resolved, array $hits): array
    {
        if ($hits === []) {
            return $resolved;
        }

        $facts = $resolved['facts'];
        $facts['faq_citations'] = array_map(static function (array $hit): array {
            return [
                'chunk_id' => (string) ($hit['chunk_id'] ?? ''),
                'title' => (string) ($hit['title'] ?? ''),
                'heading_path' => $hit['heading_path'] ?? [],
                'snippet' => (string) ($hit['snippet'] ?? ''),
                'source' => (string) ($hit['source'] ?? 'faq.md'),
                'score' => isset($hit['score']) ? (float) $hit['score'] : null,
            ];
        }, $hits);
        $facts['faq_rag_attached'] = true;
        $resolved['facts'] = $facts;

        return $resolved;
    }
}
