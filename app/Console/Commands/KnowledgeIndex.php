<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\KnowledgeEmbedChunksJob;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H4001 (Wave 3 leverage-плана) — индексация FAQ-корпуса в knowledge_chunks.
 *
 * Тяжёлая работа (эмбеддинг через туннель) уезжает в Horizon-очередь `imports`
 * партиями по config('knowledge.index_batch_size'); сама команда только
 * парсит корпус и считает дельту. Пишется ТОЛЬКО то, чей content_hash
 * сдвинулся: повторный запуск без изменения контента не пишет ничего.
 *
 * Драйвер null → честный отказ: dense-нога выключена, индексировать нечем
 * (стоп-условие 6 плана: туннель недоступен — туннельно-независимые шаги
 * заканчиваются, остальное останавливается).
 */
class KnowledgeIndex extends Command
{
    protected $signature = 'knowledge:index
        {--force : пере-эмбеддить даже не сдвинувшиеся по хэшу чанки}
        {--sync : выполнить партии инлайн, без очереди (тесты и ручной смоук)}';

    protected $description = 'H4001: embed the FAQ corpus into knowledge_chunks (float32 LE BLOB, re-embed only moved hashes)';

    public function handle(FaqCorpusParser $parser): int
    {
        if ((string) config('knowledge.driver') === '') {
            $this->error('knowledge: dense leg disabled (KNOWLEDGE_EMBEDDING_DRIVER empty) — nothing to index');

            return self::FAILURE;
        }

        $chunks = $parser->chunks();
        if ($chunks === []) {
            $this->error('knowledge: corpus parsed to zero chunks');

            return self::FAILURE;
        }

        $model = (string) config('knowledge.embedding_model', 'bge-m3');
        $dims = (int) config('knowledge.dimensions', 1024);
        $force = (bool) $this->option('force');

        $known = DB::table('knowledge_chunks')
            ->where('model', $model)
            ->where('dims', $dims)
            ->pluck('content_hash', 'faq_chunk_id');

        $stale = [];
        foreach ($chunks as $chunk) {
            $hash = KnowledgeVectors::contentHash($model, $dims, $chunk->searchText());
            if (! $force && ($known[$chunk->chunkId] ?? null) === $hash) {
                continue;
            }
            $stale[] = [
                'faq_chunk_id' => $chunk->chunkId,
                'model' => $model,
                'dims' => $dims,
                'content_hash' => $hash,
                'text' => $chunk->searchText(),
            ];
        }

        $total = count($chunks);
        if ($stale === []) {
            $this->info("knowledge: corpus unchanged — {$total} chunks, 0 re-embedded");

            return self::SUCCESS;
        }

        $batchSize = max(1, (int) config('knowledge.index_batch_size', 16));
        $batches = array_chunk($stale, $batchSize);
        $this->info("knowledge: {$total} chunks, ".count($stale).' stale, '.count($batches).' batches');

        foreach ($batches as $batch) {
            if ((bool) $this->option('sync')) {
                dispatch_sync(new KnowledgeEmbedChunksJob($batch));

                continue;
            }
            KnowledgeEmbedChunksJob::dispatch($batch);
        }

        $this->info('knowledge: dispatched '.count($batches).' embed batches onto the imports queue');

        return self::SUCCESS;
    }
}
