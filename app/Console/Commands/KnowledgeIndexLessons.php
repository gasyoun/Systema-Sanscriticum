<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\KnowledgeEmbedChunksJob;
use App\Models\KnowledgeChunk;
use App\Models\Lesson;
use App\Services\Knowledge\LessonTranscriptChunker;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Console\Command;

/**
 * Этап 4 — индексация расшифровок уроков в knowledge_chunks (полоса `lesson`).
 *
 * Зеркалит контракт knowledge:index: эмбеддинг уезжает партиями в очередь
 * `imports`, пишется только то, чей content_hash сдвинулся, драйвер пуст —
 * честный отказ. Отличие одно и оно принципиальное: здесь есть ПРУНИНГ.
 * У FAQ корпус — файл, и лишняя строка просто никогда не совпадет с чанком;
 * у урока расшифровку могут снять или перезалить, и без уборки старые
 * фрагменты остались бы в выдаче бота как живые.
 *
 * Запускается ежедневно (см. bootstrap/app.php), чтобы свежие расшифровки
 * из n8n подхватывались сами.
 */
class KnowledgeIndexLessons extends Command
{
    protected $signature = 'knowledge:index-lessons
        {lesson? : id одного урока (по умолчанию — все опубликованные с расшифровкой)}
        {--force : пере-эмбеддить даже не сдвинувшиеся по хэшу чанки}
        {--sync : выполнить партии инлайн, без очереди (тесты и ручной смоук)}
        {--limit= : ограничить число уроков (обкатка)}';

    protected $description = 'Этап 4: embed lesson transcripts into knowledge_chunks (source_type=lesson, prunes stale chunks)';

    public function handle(LessonTranscriptChunker $chunker): int
    {
        if ((string) config('knowledge.driver') === '') {
            $this->error('knowledge: dense leg disabled (KNOWLEDGE_EMBEDDING_DRIVER empty) — nothing to index');

            return self::FAILURE;
        }

        $model = (string) config('knowledge.embedding_model', 'bge-m3');
        $dims = (int) config('knowledge.dimensions', 1024);
        $force = (bool) $this->option('force');

        $lessons = Lesson::query()
            ->with('course')
            ->where('is_published', true)
            ->withTranscript()
            ->when($this->argument('lesson'), fn ($q) => $q->whereKey((int) $this->argument('lesson')))
            ->when($this->option('limit'), fn ($q) => $q->limit((int) $this->option('limit')))
            ->orderBy('id')
            ->get();

        $partial = $this->argument('lesson') !== null || $this->option('limit') !== null;

        if ($lessons->isEmpty()) {
            // Ровно этот случай и означает «у последнего урока сняли
            // расшифровку»: индексировать нечего, а вот прибрать — есть что.
            // Ранний выход до уборки оставлял бы фрагменты в выдаче бота.
            $pruned = $partial ? 0 : $this->prune([], [], false);
            $this->info('knowledge: нет опубликованных уроков с расшифровкой — индексировать нечего'
                .($pruned > 0 ? ", прибрано фрагментов: {$pruned}" : ''));

            return self::SUCCESS;
        }

        $known = KnowledgeChunk::query()
            ->where('source_type', KnowledgeChunk::SOURCE_LESSON)
            ->where('model', $model)
            ->where('dims', $dims)
            ->pluck('content_hash', 'faq_chunk_id');

        $stale = [];
        $seen = [];
        $chunkTotal = 0;

        foreach ($lessons as $lesson) {
            $chunks = $chunker->chunks($lesson);
            if ($chunks === []) {
                $this->warn("knowledge: урок {$lesson->id} — расшифровка пуста или нечитаема, пропуск");

                continue;
            }

            $chunkTotal += count($chunks);

            foreach ($chunks as $chunk) {
                $seen[] = $chunk->chunkId;
                $hash = KnowledgeVectors::contentHash($model, $dims, $chunk->searchText());
                if (! $force && ($known[$chunk->chunkId] ?? null) === $hash) {
                    continue;
                }

                $stale[] = [
                    'faq_chunk_id' => $chunk->chunkId,
                    'source_type' => KnowledgeChunk::SOURCE_LESSON,
                    'lesson_id' => $chunk->lessonId,
                    'course_id' => $chunk->courseId,
                    'start_seconds' => $chunk->startSeconds,
                    'model' => $model,
                    'dims' => $dims,
                    'content_hash' => $hash,
                    'text' => $chunk->searchText(),
                    'store_text' => $chunk->text,
                ];
            }
        }

        $pruned = $this->prune(array_map('intval', $lessons->modelKeys()), $seen, $partial);
        if ($pruned > 0) {
            $this->info("knowledge: прибрано устаревших фрагментов — {$pruned}");
        }

        if ($stale === []) {
            $this->info("knowledge: уроков {$lessons->count()}, фрагментов {$chunkTotal}, переэмбеддено 0");

            return self::SUCCESS;
        }

        $batchSize = max(1, (int) config('knowledge.index_batch_size', 16));
        $batches = array_chunk($stale, $batchSize);
        $this->info("knowledge: уроков {$lessons->count()}, фрагментов {$chunkTotal}, устарело ".count($stale).', партий '.count($batches));

        foreach ($batches as $batch) {
            if ((bool) $this->option('sync')) {
                dispatch_sync(new KnowledgeEmbedChunksJob($batch));

                continue;
            }
            KnowledgeEmbedChunksJob::dispatch($batch);
        }

        $this->info('knowledge: отправлено '.count($batches).' партий в очередь imports');

        return self::SUCCESS;
    }

    /**
     * Уборка в двух видах.
     *
     * 1. Переставшие существовать фрагменты обработанных уроков — текст
     *    перерезан на другие границы, расшифровку перезалили короче.
     * 2. Уроки, вылетевшие из выборки целиком: сняли расшифровку, сняли с
     *    публикации, удалили. Их в $lessons уже нет, поэтому первая ветка их
     *    не увидит — нужен отдельный проход по всему индексу. Делается ТОЛЬКО
     *    на полном прогоне: при --lesson/--limit это снесло бы индекс всех
     *    остальных уроков.
     *
     * @param  list<int>  $lessonIds
     * @param  list<string>  $seen
     */
    private function prune(array $lessonIds, array $seen, bool $partial): int
    {
        $pruned = 0;

        if ($lessonIds !== []) {
            $pruned += KnowledgeChunk::query()
                ->where('source_type', KnowledgeChunk::SOURCE_LESSON)
                ->whereIn('lesson_id', $lessonIds)
                ->when($seen !== [], fn ($q) => $q->whereNotIn('faq_chunk_id', $seen))
                ->delete();
        }

        if (! $partial) {
            $alive = Lesson::query()
                ->where('is_published', true)
                ->withTranscript()
                ->pluck('id')
                ->all();

            $pruned += KnowledgeChunk::query()
                ->where('source_type', KnowledgeChunk::SOURCE_LESSON)
                ->when($alive !== [], fn ($q) => $q->whereNotIn('lesson_id', $alive))
                ->delete();
        }

        return $pruned;
    }
}
