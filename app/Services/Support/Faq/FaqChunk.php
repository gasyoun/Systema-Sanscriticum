<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

/**
 * One stable FAQ section for BM25 retrieval (H2448).
 * chunk_id is derived from the heading path and survives re-exports
 * as long as section titles stay the same.
 */
final class FaqChunk
{
    /**
     * @param  list<string>  $headingPath  e.g. ["Политика и поддержка", "Оплата: блоки…"]
     */
    public function __construct(
        public readonly string $chunkId,
        public readonly array $headingPath,
        public readonly string $title,
        public readonly string $body,
        public readonly string $source = 'faq.md',
    ) {}

    public function searchText(): string
    {
        return trim(implode(' ', $this->headingPath)."\n".$this->body);
    }

    public function snippet(int $maxChars = 280): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($this->body)) ?? '';
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars - 1)).'…';
    }

    /** @return array{chunk_id: string, title: string, heading_path: list<string>, snippet: string, source: string} */
    public function toCitation(int $snippetChars = 280): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'title' => $this->title,
            'heading_path' => $this->headingPath,
            'snippet' => $this->snippet($snippetChars),
            'source' => $this->source,
        ];
    }
}
