<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * H4001 — клиент Ollama /api/embed за sshd-туннелем на GPU-узел.
 *
 * Ровно одна попытка на вызов: Http::throw() на 4xx/5xx, ConnectionException
 * при обрыве туннеля. Таймаут и база — только из config/knowledge.php
 * (рулинг D2). Размерность сверяется с конфигом — рассинхрон модели и
 * knowledge_chunks должен падать громко, а не тихо портить косинусы.
 */
final class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? [];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $timeout = max(1, (int) config('knowledge.timeout', 5));
        $base = rtrim((string) config('knowledge.base_url', 'http://127.0.0.1:11434'), '/');
        $model = (string) config('knowledge.embedding_model', 'bge-m3');
        $dims = (int) config('knowledge.dimensions', 1024);

        $response = Http::timeout($timeout)
            ->throw()
            ->post($base.'/api/embed', [
                'model' => $model,
                'input' => array_values($texts),
            ]);

        $embeddings = $response->json('embeddings');
        if (! is_array($embeddings) || count($embeddings) !== count($texts)) {
            throw new RuntimeException(sprintf(
                'knowledge: malformed /api/embed response (model %s): expected %d vectors, got %s',
                $model,
                count($texts),
                is_array($embeddings) ? (string) count($embeddings) : gettype($embeddings),
            ));
        }

        $out = [];
        foreach ($embeddings as $i => $vector) {
            if (! is_array($vector) || count($vector) !== $dims) {
                throw new RuntimeException(sprintf(
                    'knowledge: dimension drift (model %s): expected %d, got %s (item %d)',
                    $model,
                    $dims,
                    is_array($vector) ? (string) count($vector) : gettype($vector),
                    $i,
                ));
            }
            $out[] = array_map('floatval', array_values($vector));
        }

        return $out;
    }
}
