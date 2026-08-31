<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

use Illuminate\Support\Facades\Cache;

/**
 * Split resources/knowledge/faq.md into stable heading-path chunks (H2448).
 * Does NOT hand-edit the file — ORS-FAQ export remains the sole author source.
 */
final class FaqCorpusParser
{
    private const CACHE_KEY = 'support.faq_rag.chunks.v1';

    private const CACHE_TTL = 600;

    public function path(): string
    {
        $configured = config('support.faq_rag.path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return resource_path('knowledge/faq.md');
    }

    /**
     * H3766 B3 (правка 4): дополнительные корпуса рядом с ORS-экспортом.
     *
     * `faq_from_lectures.md` наполняет KnowledgeFaqPublisher при Accept
     * faq_draft (H1549 Wave 3). BotKnowledgeBase читал оба файла с самого
     * начала, а BM25-ретривер — только faq.md, поэтому лекционные ответы были
     * невидимы для подсказок куратору. Игнорируется явный `$path` — там вызов
     * просит конкретный файл (тесты, кастомный корпус).
     *
     * @return list<string>
     */
    public function extraPaths(): array
    {
        $configured = config('support.faq_rag.extra_paths');
        if (is_array($configured)) {
            return array_values(array_filter(array_map('strval', $configured), static fn (string $p): bool => $p !== ''));
        }

        $lecture = config('content.lecture_faq_path', resource_path('knowledge/faq_from_lectures.md'));

        return is_string($lecture) && $lecture !== '' ? [$lecture] : [];
    }

    /**
     * @return list<FaqChunk>
     */
    public function chunks(?string $path = null): array
    {
        $explicit = $path !== null;
        $path ??= $this->path();

        $chunks = $this->chunksOfFile($path);
        if ($explicit) {
            return $chunks;
        }

        foreach ($this->extraPaths() as $extra) {
            if ($extra === $path) {
                continue;
            }
            $stem = pathinfo($extra, PATHINFO_FILENAME);
            foreach ($this->chunksOfFile($extra) as $chunk) {
                // Префикс именем файла: заголовки соседнего корпуса могут
                // совпасть с faq.md, а chunk_id обязан оставаться уникальным —
                // на него ссылается фикстура eval-набора и цитата в ответе.
                $chunks[] = new FaqChunk(
                    $stem.'/'.$chunk->chunkId,
                    $chunk->headingPath,
                    $chunk->title,
                    $chunk->body,
                    basename($extra),
                );
            }
        }

        return $chunks;
    }

    /**
     * @return list<FaqChunk>
     */
    private function chunksOfFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $mtime = (int) filemtime($path);
        $cacheKey = self::CACHE_KEY.':'.$mtime.':'.md5($path);

        /** @var list<array{chunk_id: string, heading_path: list<string>, title: string, body: string, source: string}> $raw */
        $raw = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($path): array {
            return array_map(
                static fn (FaqChunk $c): array => [
                    'chunk_id' => $c->chunkId,
                    'heading_path' => $c->headingPath,
                    'title' => $c->title,
                    'body' => $c->body,
                    'source' => $c->source,
                ],
                $this->parseFile($path),
            );
        });

        return array_map(
            static fn (array $row): FaqChunk => new FaqChunk(
                $row['chunk_id'],
                $row['heading_path'],
                $row['title'],
                $row['body'],
                $row['source'],
            ),
            $raw,
        );
    }

    /**
     * @return list<FaqChunk>
     */
    public function parseFile(string $path): array
    {
        $text = (string) file_get_contents($path);
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];

        $h2 = null;
        $h3 = null;
        $bodyLines = [];
        $chunks = [];

        $flush = function () use (&$chunks, &$h2, &$h3, &$bodyLines): void {
            if ($h3 === null && $h2 === null) {
                $bodyLines = [];

                return;
            }

            $body = trim(implode("\n", $bodyLines));
            // Skip empty / header-only blocks
            if ($body === '' && $h3 === null) {
                $bodyLines = [];

                return;
            }

            $path = array_values(array_filter([$h2, $h3], static fn (?string $s): bool => $s !== null && $s !== ''));
            if ($path === []) {
                $bodyLines = [];

                return;
            }

            $title = $h3 ?? $h2 ?? '';
            $chunkId = $this->chunkIdFromPath($path);
            $chunks[] = new FaqChunk($chunkId, $path, $title, $body, basename($this->path()));
            $bodyLines = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^###\s+(.+)$/u', $line, $m)) {
                $flush();
                $h3 = trim($m[1]);
                $bodyLines = [];

                continue;
            }
            if (preg_match('/^##\s+(.+)$/u', $line, $m)) {
                $flush();
                $h2 = trim($m[1]);
                $h3 = null;
                $bodyLines = [];

                continue;
            }
            if (preg_match('/^#\s+/u', $line)) {
                // Document H1 — ignore as chunk boundary
                continue;
            }
            if (str_starts_with(trim($line), '<!--')) {
                continue;
            }

            $bodyLines[] = $line;
        }
        $flush();

        return $chunks;
    }

    /**
     * @param  list<string>  $path
     */
    public function chunkIdFromPath(array $path): string
    {
        $parts = array_map(static function (string $part): string {
            // Cyrillic-preserving slug: stable across re-exports when titles match.
            $slug = mb_strtolower(trim($part), 'UTF-8');
            $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug) ?? '';
            $slug = trim($slug, '-');
            if ($slug === '') {
                $slug = 'section-'.substr(md5($part), 0, 8);
            }

            return $slug;
        }, $path);

        return implode('/', $parts);
    }

    public function forgetCache(): void
    {
        // Best-effort: key includes mtime, so next read is fresh after export.
        Cache::forget(self::CACHE_KEY);
    }
}
