<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Faq\NullEmbeddingProvider;
use App\Services\Support\Faq\OllamaEmbeddingProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * H4001 — OllamaEmbeddingProvider: одна попытка, таймаут из конфига,
 * malformed-ответ и расхождение размерности падают громко;
 * NullEmbeddingProvider: пусто + ровно один warning.
 */
class KnowledgeEmbeddingProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'knowledge.base_url' => 'http://127.0.0.1:11434',
            'knowledge.embedding_model' => 'bge-m3',
            'knowledge.dimensions' => 4,
            'knowledge.timeout' => 5,
        ]);
    }

    public function test_embed_posts_once_to_api_embed_and_parses_floats(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/embed' => Http::response([
                'model' => 'bge-m3',
                'embeddings' => [[0.5, -1.25, 2.0, 0.0]],
            ]),
        ]);

        $vector = (new OllamaEmbeddingProvider)->embed('как оплатить курс');

        $this->assertSame([0.5, -1.25, 2.0, 0.0], $vector);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request['model'] === 'bge-m3'
                && in_array('как оплатить курс', (array) $request['input'], true);
        });
    }

    public function test_embed_batch_keeps_input_order(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/embed' => Http::response([
                'embeddings' => [[0.0, 0.0, 0.0, 1.0], [1.0, 0.0, 0.0, 0.0]],
            ]),
        ]);

        $vectors = (new OllamaEmbeddingProvider)->embedBatch(['первый', 'второй']);

        $this->assertCount(2, $vectors);
        $this->assertSame([0.0, 0.0, 0.0, 1.0], $vectors[0]);
        $this->assertSame([1.0, 0.0, 0.0, 0.0], $vectors[1]);
        Http::assertSentCount(1);
    }

    public function test_malformed_response_throws_loudly(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/embed' => Http::response(['unexpected' => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed /api/embed response');

        (new OllamaEmbeddingProvider)->embed('вопрос');
    }

    public function test_dimension_drift_throws_loudly(): void
    {
        config(['knowledge.dimensions' => 4]);
        Http::fake([
            '127.0.0.1:11434/api/embed' => Http::response([
                'embeddings' => [[0.1, 0.2, 0.3]],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dimension drift');

        (new OllamaEmbeddingProvider)->embed('вопрос');
    }

    public function test_http_error_throws_without_retries(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/embed' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('500');

        try {
            (new OllamaEmbeddingProvider)->embed('вопрос');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_empty_batch_is_a_noop(): void
    {
        Http::fake();

        $this->assertSame([], (new OllamaEmbeddingProvider)->embedBatch([]));
        Http::assertSentCount(0);
    }

    public function test_null_provider_returns_empty_and_warns_once_per_call(): void
    {
        Log::spy();

        $provider = new NullEmbeddingProvider;

        $this->assertSame([], $provider->embed('вопрос'));
        $this->assertSame([[], [], []], $provider->embedBatch(['а', 'б', 'в']));

        Log::shouldHaveReceived('warning')->twice();
    }
}
