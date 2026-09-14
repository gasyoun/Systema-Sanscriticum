<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Jobs\KnowledgeEmbedChunksJob;
use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * H4001 — knowledge:index: дельта по content_hash, партии в очередь imports,
 * повторный запуск без изменений не пишет ничего, driver null = честный отказ.
 */
class KnowledgeIndexTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpFaq;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpFaq = storage_path('framework/testing/faq_knowledge_index_h4001.md');
        File::ensureDirectoryExists(dirname($this->tmpFaq));
        File::put($this->tmpFaq, <<<'MD'
# FAQ test

## Политика и поддержка

### Оплата: блоки
Оплата курса идёт поблочно.

### Записи уроков
Запись каждого занятия в чате курса.
MD);

        config([
            'support.faq_rag.path' => $this->tmpFaq,
            'support.faq_rag.extra_paths' => [],
            'knowledge.driver' => 'ollama',
            'knowledge.dimensions' => 4,
            'knowledge.embedding_model' => 'test-model',
            'knowledge.index_batch_size' => 16,
        ]);
    }

    public function test_sync_index_writes_vectors_and_rerun_without_change_writes_nothing(): void
    {
        $fake = new FakeEmbeddingProvider(default: [1.0, 0.0, 0.0, 0.0]);
        $this->app->instance(EmbeddingProvider::class, $fake);

        $this->artisan('knowledge:index', ['--sync' => true])->assertSuccessful();
        $this->assertSame(2, KnowledgeChunk::count(), 'both h3-chunks indexed');
        $this->assertSame(1, $fake->calls, 'one batched embed call');

        $row = KnowledgeChunk::query()
            ->where('faq_chunk_id', 'политика-и-поддержка/оплата-блоки')
            ->firstOrFail();
        $this->assertSame(KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]), $row->embedding);
        $this->assertSame(4, $row->dims);
        $this->assertSame(
            KnowledgeVectors::contentHash(
                'test-model',
                4,
                'Политика и поддержка Оплата: блоки'."\n".'Оплата курса идёт поблочно.',
            ),
            $row->content_hash,
        );

        $stamp = $row->updated_at?->toIso8601String();

        // Корпус не менялся → ничего не пишется (контракт H4001).
        $this->artisan('knowledge:index', ['--sync' => true])->assertSuccessful()
            ->expectsOutputToContain('0 re-embedded');
        $this->assertSame(2, KnowledgeChunk::count());
        $this->assertSame($stamp, KnowledgeChunk::query()->find($row->id)?->updated_at?->toIso8601String());
    }

    public function test_only_moved_hashes_are_re_embedded(): void
    {
        $fake = new FakeEmbeddingProvider(default: [0.0, 1.0, 0.0, 0.0]);
        $this->app->instance(EmbeddingProvider::class, $fake);
        $this->artisan('knowledge:index', ['--sync' => true])->assertSuccessful();

        File::put($this->tmpFaq, <<<'MD'
# FAQ test

## Политика и поддержка

### Оплата: блоки
Оплата курса идёт ПОБЛОЧНО, а ещё и целиком.

### Записи уроков
Запись каждого занятия в чате курса.
MD);
        // Кэш парсера ключуется mtime с секундной гранулярностью — тест идет
        // быстрее секунды, поэтому двигаем mtime явно (forgetCache() в парсере
        // чистит только префикс-ключ и фактический суффикс не трогает).
        touch($this->tmpFaq, time() + 10);

        $fake->calls = 0;
        $this->artisan('knowledge:index', ['--sync' => true])->assertSuccessful();

        $this->assertSame(1, $fake->calls, 'one batch');
        $rows = KnowledgeChunk::all();
        $this->assertSame(2, $rows->count(), 'no duplicate rows');
        $moved = $rows->firstWhere('faq_chunk_id', 'политика-и-поддержка/оплата-блоки');
        $this->assertSame(
            KnowledgeVectors::unpack($moved->embedding),
            [0.0, 1.0, 0.0, 0.0],
            'moved chunk got the new vector',
        );
    }

    public function test_dispatch_path_queues_batches_onto_imports(): void
    {
        Queue::fake();
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(default: [1.0, 0.0, 0.0, 0.0]));

        config(['knowledge.index_batch_size' => 1]);
        $this->artisan('knowledge:index')->assertSuccessful();

        Queue::assertPushedOn('imports', KnowledgeEmbedChunksJob::class);
        Queue::assertPushed(KnowledgeEmbedChunksJob::class, 2);
    }

    public function test_missing_driver_refuses_to_index(): void
    {
        config(['knowledge.driver' => null]);

        $this->artisan('knowledge:index')
            ->expectsOutputToContain('dense leg disabled')
            ->assertFailed();
    }
}
