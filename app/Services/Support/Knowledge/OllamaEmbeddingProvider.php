<?php

declare(strict_types=1);

namespace App\Services\Support\Knowledge;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H3234 stage 3 — calls Ivan's GPU node's `bge-m3` embedding model over the
 * reverse SSH tunnel (`autossh -R 11434:localhost:11434` landing on
 * 127.0.0.1:11434 on `.92` — see docs/EXPERIMENT_OLLAMA_GPU_OCT1_2026.md).
 *
 * Never installed as the app's model host: this class only ever talks to
 * config('support.knowledge.ollama_base_url'), which in production is the
 * tunnel's localhost endpoint, never a model process started on `.92` itself
 * (H3234 acceptance: "Fail =: model on .92").
 *
 * Fails closed on every error (timeout, connection refused, non-200, bad
 * payload) — returns null instead of throwing, so a dead tunnel degrades the
 * caller to BM25-only rather than surfacing a 500 to a student.
 */
final class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly float $timeoutSeconds = 5.0,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('support.knowledge.ollama_base_url', 'http://127.0.0.1:11434'),
            (string) config('support.knowledge.embedding_model', 'bge-m3'),
            (float) config('support.knowledge.ollama_timeout_seconds', 5.0),
        );
    }

    public function embed(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout((int) ceil($this->timeoutSeconds))
                ->post('/api/embeddings', [
                    'model' => $this->model,
                    'prompt' => $text,
                ]);
        } catch (Throwable $e) {
            Log::warning('H3234 OllamaEmbeddingProvider: request failed, degrading to BM25', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('H3234 OllamaEmbeddingProvider: non-200, degrading to BM25', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $vector = $response->json('embedding');
        if (! is_array($vector) || $vector === []) {
            return null;
        }

        return array_map(static fn ($v) => (float) $v, array_values($vector));
    }

    public function modelName(): string
    {
        return $this->model;
    }
}
