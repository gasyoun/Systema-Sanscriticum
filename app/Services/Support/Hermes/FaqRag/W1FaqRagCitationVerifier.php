<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\FaqRag;

use App\Services\Support\Faq\FaqChunk;
use App\Services\Support\Faq\FaqCorpusParser;

/**
 * H6185 (Epic E026, Hermes W1): верификатор цитат роли 20 `faq-rag`.
 *
 * Каждая цитата ответа — запись (chunk_id, snippet) — обязана разрешаться
 * против УТВЕРЖДЁННОГО FAQ-корпуса (resources/knowledge/faq.md; тот же
 * источник, что у ретривала H2448 и citation-key drift gate H6917). Три
 * исхода отказа — ровно как в миссии H6185:
 *
 *  - missing        — ключ не разрешим в корпусе (drift переименовал раздел);
 *  - stale          — ключ есть, но подтверждающего текста больше нет
 *                     (re-export изменил тело раздела) либо подтверждение
 *                     пустое — проверять нечего;
 *  - contradictory  — подтверждающий текст в корпусе живёт, но под ДРУГИМ
 *                     ключом: цитата приписана не тому разделу (мисс-
 *                     атрибуция). Операционализация «противоречивой цитаты»
 *                     в W1 — именно мисс-атрибуция: сверка ответа с фактом
 *                     раздела (NLI) в mock-пути не производится.
 *
 * Любая непроверенная цитата = ответ не проходит: отказ или человеку.
 * Verifier читает корпус только на чтение; никаких записей и отправок.
 */
final class W1FaqRagCitationVerifier
{
    public const PROBLEM_MISSING = 'missing';

    public const PROBLEM_STALE = 'stale';

    public const PROBLEM_CONTRADICTORY = 'contradictory';

    public function __construct(
        private readonly FaqCorpusParser $parser = new FaqCorpusParser,
    ) {}

    /**
     * @param  list<array{chunk_id: string, snippet: string}>  $citations  записи цитат ответа
     * @return array{verified: list<array{chunk_id: string, snippet: string}>, failed: list<array{chunk_id: string, problem: string}>}
     */
    public function verify(array $citations, ?string $corpusPath = null): array
    {
        /** @var array<string, FaqChunk> $byId */
        $byId = [];
        foreach ($this->parser->chunks($corpusPath) as $chunk) {
            $byId[$chunk->chunkId] = $chunk;
        }

        $verified = [];
        $failed = [];

        foreach ($citations as $citation) {
            $key = (string) ($citation['chunk_id'] ?? '');
            $snippet = trim((string) ($citation['snippet'] ?? ''));

            $chunk = $byId[$key] ?? null;
            if ($chunk === null) {
                $failed[] = ['chunk_id' => $key, 'problem' => self::PROBLEM_MISSING];

                continue;
            }

            if ($snippet === '' || ! $this->contains($chunk, $snippet)) {
                // Текст цитаты живёт в корпусе, но под другим ключом —
                // мисс-атрибуция (contradictory). Пустой сниппет сюда не
                // доходит: пустое подтверждение = stale (проверять нечего).
                if ($snippet !== '') {
                    foreach ($byId as $otherId => $other) {
                        if ($otherId !== $key && $this->contains($other, $snippet)) {
                            $failed[] = ['chunk_id' => $key, 'problem' => self::PROBLEM_CONTRADICTORY];

                            continue 2;
                        }
                    }
                }

                $failed[] = ['chunk_id' => $key, 'problem' => self::PROBLEM_STALE];

                continue;
            }

            $verified[] = ['chunk_id' => $key, 'snippet' => $snippet];
        }

        return ['verified' => $verified, 'failed' => $failed];
    }

    /** Сниппет (в т.ч. обрезанный «…») обязан жить в тексте своего раздела. */
    private function contains(FaqChunk $chunk, string $snippet): bool
    {
        $needle = $this->normalize($snippet);
        $needle = preg_replace('/…$/u', '', $needle) ?? $needle;

        return $needle !== '' && str_contains($this->normalize($chunk->title.' '.$chunk->body), $needle);
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? '');
    }
}
