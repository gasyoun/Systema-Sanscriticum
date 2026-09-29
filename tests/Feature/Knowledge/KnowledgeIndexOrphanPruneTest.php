<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * H5065-follow-up — осиротевшие строки FAQ-полосы в knowledge_chunks.
 *
 * Индекс ключуется по chunk_id, а тот выводится из заголовков разделов: при
 * пере-экспорте корпуса заголовок меняется, и старая строка остаётся навсегда
 * (команда умела только upsert). На проде 18-09-2026 это 159 строк против 82
 * чанков. Набор пинит четыре вещи:
 *  1. без флага сироты видны в выводе, но НЕ удаляются;
 *  2. с `--prune-orphans` удаляются только сироты FAQ-полосы этой модели —
 *     уроки и строки другой модели остаются;
 *  3. уборка работает и при пустом драйвере эмбеддингов (она не требует ни
 *     модели, ни туннеля);
 *  4. пустой корпус НИКОГДА не приводит к удалению — иначе пропавший файл
 *     однажды вычистил бы всю таблицу.
 */
class KnowledgeIndexOrphanPruneTest extends TestCase
{
    use RefreshDatabase;

    private string $corpus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corpus = storage_path('framework/testing/ki_orphans_'.uniqid().'.md');
        File::ensureDirectoryExists(dirname($this->corpus));
        File::put($this->corpus, implode("\n", [
            '# FAQ',
            '',
            '## Политика и поддержка',
            '',
            '### Сертификат',
            'Грамота выдаётся по окончании курса.',
            '',
        ]));

        config([
            'support.faq_rag.path' => $this->corpus,
            'support.faq_rag.extra_paths' => [],
            'knowledge.driver' => 'ollama',
            'knowledge.embedding_model' => 'test-model',
            'knowledge.dimensions' => 4,
        ]);

        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(default: [1.0, 0.0, 0.0, 0.0]));

        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->corpus);
        parent::tearDown();
    }

    public function test_orphans_are_reported_but_kept_without_the_flag(): void
    {
        $this->seedOrphan('политика-и-поддержка/старый-заголовок-которого-нет');

        $this->artisan('knowledge:index', ['--sync' => true])
            ->expectsOutputToContain('осиротевш')
            ->assertExitCode(0);

        $this->assertDatabaseHas('knowledge_chunks', [
            'faq_chunk_id' => 'политика-и-поддержка/старый-заголовок-которого-нет',
        ]);
    }

    public function test_prune_deletes_only_faq_rows_outside_the_corpus(): void
    {
        $this->seedOrphan('политика-и-поддержка/старый-заголовок-которого-нет');

        // Урок и строка «чужой» модели обязаны выжить: уборка FAQ не имеет
        // права трогать соседние полосы.
        KnowledgeChunk::create([
            'faq_chunk_id' => 'lesson:1961:42',
            'source_type' => KnowledgeChunk::SOURCE_LESSON,
            'lesson_id' => 1961,
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => 'lesson-hash',
            'text' => 'фрагмент урока',
        ]);
        KnowledgeChunk::create([
            'faq_chunk_id' => 'политика-и-поддержка/строка-другой-модели',
            'source_type' => KnowledgeChunk::SOURCE_FAQ,
            'model' => 'other-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => 'other-model-hash',
        ]);

        $this->artisan('knowledge:index', ['--prune-orphans' => true, '--sync' => true])
            ->expectsOutputToContain('pruned 1 orphan rows')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('knowledge_chunks', [
            'faq_chunk_id' => 'политика-и-поддержка/старый-заголовок-которого-нет',
        ]);
        $this->assertDatabaseHas('knowledge_chunks', ['faq_chunk_id' => 'lesson:1961:42']);
        $this->assertDatabaseHas('knowledge_chunks', ['faq_chunk_id' => 'политика-и-поддержка/строка-другой-модели']);
    }

    public function test_prune_works_without_the_embedding_driver(): void
    {
        config(['knowledge.driver' => '']);
        $this->seedOrphan('политика-и-поддержка/старый-заголовок-которого-нет');

        // Без флага и без драйвера — прежний честный отказ, строки целы.
        $this->artisan('knowledge:index')->assertExitCode(1);
        $this->assertDatabaseHas('knowledge_chunks', [
            'faq_chunk_id' => 'политика-и-поддержка/старый-заголовок-которого-нет',
        ]);

        // С флагом та же команда убирает сирот: эмбеддер для уборки не нужен.
        $this->artisan('knowledge:index', ['--prune-orphans' => true])->assertExitCode(0);
        $this->assertDatabaseMissing('knowledge_chunks', [
            'faq_chunk_id' => 'политика-и-поддержка/старый-заголовок-которого-нет',
        ]);
    }

    public function test_empty_corpus_never_prunes(): void
    {
        $this->seedOrphan('политика-и-поддержка/старый-заголовок-которого-нет');

        // Корпус «пропал»: файла нет, чанков ноль. Даже с явным флагом команда
        // обязана отказать и НИЧЕГО не удалить.
        config(['support.faq_rag.path' => $this->corpus.'.missing']);

        $this->artisan('knowledge:index', ['--prune-orphans' => true, '--sync' => true])
            ->expectsOutputToContain('corpus parsed to zero chunks')
            ->assertExitCode(1);

        $this->assertDatabaseHas('knowledge_chunks', [
            'faq_chunk_id' => 'политика-и-поддержка/старый-заголовок-которого-нет',
        ]);
    }

    private function seedOrphan(string $chunkId): void
    {
        KnowledgeChunk::create([
            'faq_chunk_id' => $chunkId,
            'source_type' => KnowledgeChunk::SOURCE_FAQ,
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]),
            'content_hash' => 'hash-of-a-heading-that-no-longer-exists',
        ]);
    }
}
