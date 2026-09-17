<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Jobs\KnowledgeEmbedChunksJob;
use App\Models\Course;
use App\Models\KnowledgeChunk;
use App\Models\Lesson;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * Этап 4 — контракт knowledge:index-lessons: дельта по content_hash, партии в
 * очередь imports, уборка устаревших фрагментов и полная изоляция FAQ-полосы.
 */
class LessonIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmbeddingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'features.lesson_qa' => true,
            'knowledge.driver' => 'ollama',
            'knowledge.dimensions' => 4,
            'knowledge.embedding_model' => 'test-model',
            'knowledge.index_batch_size' => 16,
            'knowledge.lesson.chunk_chars' => 200,
        ]);

        $this->fake = new FakeEmbeddingProvider(default: [1.0, 0.0, 0.0, 0.0]);
        $this->app->instance(EmbeddingProvider::class, $this->fake);
    }

    public function test_lesson_chunks_are_written_with_source_and_provenance(): void
    {
        $lesson = $this->lessonWithTranscript('Первое предложение урока. Второе предложение урока.');

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        $row = KnowledgeChunk::query()->where('source_type', KnowledgeChunk::SOURCE_LESSON)->firstOrFail();
        $this->assertSame('lesson-'.$lesson->id.'/41', $row->faq_chunk_id);
        $this->assertSame($lesson->id, $row->lesson_id);
        $this->assertSame((string) $lesson->course_id, $row->course_id);
        $this->assertSame(41, $row->start_seconds);
        $this->assertSame(KnowledgeVectors::pack([1.0, 0.0, 0.0, 0.0]), $row->embedding);
        $this->assertNotNull($row->text, 'текст фрагмента нужен лексической ноге');
        $this->assertStringContainsString('Первое предложение урока.', (string) $row->text);
    }

    public function test_rerun_without_changes_writes_nothing(): void
    {
        $this->lessonWithTranscript('Неизменное предложение.');

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();
        $callsAfterFirst = $this->fake->calls;

        $this->artisan('knowledge:index-lessons', ['--sync' => true])
            ->expectsOutputToContain('переэмбеддено 0')
            ->assertSuccessful();

        $this->assertSame($callsAfterFirst, $this->fake->calls, 'второй прогон не должен звать эмбеддинги');
    }

    public function test_changed_transcript_is_reembedded(): void
    {
        $lesson = $this->lessonWithTranscript('Старый текст занятия.');
        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();
        $before = KnowledgeChunk::query()->where('source_type', KnowledgeChunk::SOURCE_LESSON)->firstOrFail()->content_hash;

        Storage::disk('local')->put(
            (string) $lesson->transcript_file,
            json_encode($this->deepgram('Новый текст занятия целиком.'), JSON_UNESCAPED_UNICODE),
        );
        // TranscriptParser кэширует предложения ключом «диск + путь + mtime».
        // В проде перезаливка идёт секундами позже и ключ меняется сам; в тесте
        // обе записи попадают в одну секунду, поэтому кэш сбрасываем руками —
        // иначе индексатор честно увидел бы старый текст.
        Cache::flush();

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();
        $after = KnowledgeChunk::query()->where('source_type', KnowledgeChunk::SOURCE_LESSON)->firstOrFail()->content_hash;

        $this->assertNotSame($before, $after, 'изменённая расшифровка обязана переэмбеддиться');
    }

    public function test_chunks_are_pruned_when_transcript_is_removed(): void
    {
        $lesson = $this->lessonWithTranscript('Занятие с расшифровкой.');
        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();
        $this->assertSame(1, KnowledgeChunk::where('source_type', KnowledgeChunk::SOURCE_LESSON)->count());

        // Расшифровку сняли — фрагменты обязаны исчезнуть из поиска, иначе бот
        // продолжит цитировать то, чего у урока больше нет.
        $lesson->forceFill(['transcript_file' => null])->save();

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        $this->assertSame(0, KnowledgeChunk::where('source_type', KnowledgeChunk::SOURCE_LESSON)->count());
    }

    public function test_partial_run_never_prunes_other_lessons(): void
    {
        $first = $this->lessonWithTranscript('Первое занятие.');
        $second = $this->lessonWithTranscript('Второе занятие.');
        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();
        $this->assertSame(2, KnowledgeChunk::where('source_type', KnowledgeChunk::SOURCE_LESSON)->count());

        $this->artisan('knowledge:index-lessons', ['lesson' => $first->id, '--sync' => true])->assertSuccessful();

        $this->assertSame(
            2,
            KnowledgeChunk::where('source_type', KnowledgeChunk::SOURCE_LESSON)->count(),
            'частичный прогон не имеет права чистить индекс остальных уроков',
        );
        $this->assertTrue(
            KnowledgeChunk::where('lesson_id', $second->id)->exists(),
            'фрагмент необработанного урока уцелел',
        );
    }

    public function test_faq_rows_are_untouched_by_the_lesson_indexer(): void
    {
        $faqRow = KnowledgeChunk::create([
            'faq_chunk_id' => 'политика-и-поддержка/оплата',
            'source_type' => KnowledgeChunk::SOURCE_FAQ,
            'model' => 'test-model',
            'dims' => 4,
            'embedding' => KnowledgeVectors::pack([0.0, 1.0, 0.0, 0.0]),
            'content_hash' => 'faq-hash',
        ]);

        $this->lessonWithTranscript('Занятие про сандхи.');
        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        $this->assertDatabaseHas('knowledge_chunks', [
            'id' => $faqRow->id,
            'content_hash' => 'faq-hash',
            'source_type' => KnowledgeChunk::SOURCE_FAQ,
        ]);
        $this->assertSame(1, KnowledgeChunk::where('source_type', KnowledgeChunk::SOURCE_LESSON)->count());
    }

    public function test_batches_go_to_the_imports_queue(): void
    {
        Queue::fake();
        $this->lessonWithTranscript('Занятие для очереди.');

        $this->artisan('knowledge:index-lessons')->assertSuccessful();

        Queue::assertPushedOn('imports', KnowledgeEmbedChunksJob::class);
    }

    public function test_missing_driver_refuses_to_index(): void
    {
        config(['knowledge.driver' => '']);

        $this->artisan('knowledge:index-lessons')->assertFailed();
    }

    private function lessonWithTranscript(string $text): Lesson
    {
        $lesson = Lesson::factory()->for(Course::factory())->create([
            'is_published' => true,
            'group_id' => null,
        ]);

        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->put($path, json_encode($this->deepgram($text), JSON_UNESCAPED_UNICODE));
        $lesson->forceFill(['transcript_file' => $path])->save();

        return $lesson->refresh();
    }

    private function deepgram(string $text): array
    {
        $words = [];
        $start = 41.0;
        foreach (explode(' ', $text) as $word) {
            $words[] = ['word' => $word, 'punctuated_word' => $word, 'start' => $start, 'end' => $start + 0.4];
            $start += 0.5;
        }

        return ['results' => ['channels' => [['alternatives' => [['words' => $words]]]]]];
    }
}
