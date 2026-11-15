<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\FaqRag;

use App\Services\Support\Faq\FaqChunk;

/**
 * H6185: детерминированный mock-путь LLM для роли 20 `faq-rag`.
 *
 * Извлекает ответ из top-разделов корпуса и вешает на каждый маркер
 * [cite:chunk_id] — без этого роль не имеет права отвечать (must_cite).
 * Никаких сетевых вызовов и платных API: mock существует, чтобы PII-граница
 * (H6182) и верификация цитат были проверяемы без внешних зависимостей.
 */
final class MockFaqRagAnswerLlm implements FaqRagAnswerLlm
{
    /** Сколько разделов mock-ответ цитирует. */
    private const MAX_CITED = 3;

    public function complete(string $maskedQuestion, array $chunks): string
    {
        $lines = ['Ответ по базе знаний (FAQ):'];

        foreach (array_slice($chunks, 0, self::MAX_CITED) as $chunk) {
            assert($chunk instanceof FaqChunk);
            $lines[] = '— '.$chunk->snippet().' [cite:'.$chunk->chunkId.']';
        }

        return trim(implode("\n", $lines));
    }
}
