<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

use Illuminate\Support\Facades\Log;

/**
 * H4001 — dense-ноги нет: туннель недоступен или KNOWLEDGE_EMBEDDING_DRIVER
 * пуст. Возвращает пустые вектора + ОДНУ строку warning и оставляет lane
 * байт-в-байт на BM25 (контракт: пустой embedding = «dense-нога недоступна»).
 * Тесты и прод-без-туннеля ведут себя идентично.
 */
final class NullEmbeddingProvider implements EmbeddingProvider
{
    private const WARNING = 'knowledge: embedding driver unavailable — dense leg skipped, BM25 floor retained';

    /** @return list<float> */
    public function embed(string $text): array
    {
        Log::warning(self::WARNING);

        return [];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        Log::warning(self::WARNING);

        return array_fill(0, count($texts), []);
    }
}
