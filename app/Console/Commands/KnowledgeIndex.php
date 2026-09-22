<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\KnowledgeEmbedChunksJob;
use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\KnowledgeVectors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H4001 (Wave 3 leverage-плана) — индексация FAQ-корпуса в knowledge_chunks.
 *
 * Тяжелая работа (эмбеддинг через туннель) уезжает в Horizon-очередь `imports`
 * партиями по config('knowledge.index_batch_size'); сама команда только
 * парсит корпус и считает дельту. Пишется ТОЛЬКО то, чей content_hash
 * сдвинулся: повторный запуск без изменения контента не пишет ничего.
 *
 * H5065-follow-up — ОСИРОТЕВШИЕ СТРОКИ. Индекс над корпусом ключуется по
 * chunk_id, а chunk_id выводится из заголовков разделов; при пере-экспорте
 * faq.md из ORS-FAQ заголовки меняются (`записи-уроков-и-пропуски` →
 * `записи-пропуски`), и старая строка остается в таблице навсегда: команда
 * умеет только upsert, удалять она не умела. Замер на проде 18-09-2026:
 * 82 чанка корпуса против 159 строк FAQ-полосы — 77 сирот, ~308 КБ BLOB'ов,
 * которые плотная нога тянет в память на КАЖДОМ запросе. Ответы при этом не
 * портятся (ретривал идет по чанкам корпуса и сироту не вернет), но это мертвый
 * вес и он маскирует реальный рост базы: `knowledge:coverage` показывает
 * «rows=159» и приходится считать разницу в голове.
 *
 * Почему удаление — ОПЦИЯ, а не поведение по умолчанию. Удаление строк в
 * проде — необратимая операция, а корпус может оказаться временно неполным
 * (не смонтирован том, пустой `extra_paths`, откатили экспорт). Поэтому:
 *  - сироты ВСЕГДА считаются и печатаются — их видно в ежедневном логе слота
 *    knowledge-index, даже когда удалять никто не собирался;
 *  - удаляет только явный `--prune-orphans`;
 *  - удаление невозможно, пока корпус не распарсился (пустой корпус — это
 *    FAILURE выше по коду, а не «удалить все»).
 */
class KnowledgeIndex extends Command
{
    protected $signature = 'knowledge:index
        {--force : пере-эмбеддить даже не сдвинувшиеся по хэшу чанки}
        {--prune-orphans : удалить строки FAQ-полосы, чьих chunk_id больше нет в корпусе (без флага — только показать)}
        {--sync : выполнить партии инлайн, без очереди (тесты и ручной смоук)}';

    protected $description = 'H4001: embed the FAQ corpus into knowledge_chunks (float32 LE BLOB, re-embed only moved hashes)';

    public function handle(FaqCorpusParser $parser): int
    {
        // Корпус парсится ПЕРВЫМ: и индексация, и (тем более) удаление сирот
        // обязаны опираться на непустой корпус. Пустой парс = FAILURE и никаких
        // удалений — иначе однажды пропавший файл вычистил бы всю таблицу.
        $chunks = $parser->chunks();
        if ($chunks === []) {
            $this->error('knowledge: corpus parsed to zero chunks');

            return self::FAILURE;
        }

        $model = (string) config('knowledge.embedding_model', 'bge-m3');
        $dims = (int) config('knowledge.dimensions', 1024);
        $force = (bool) $this->option('force');
        $prune = (bool) $this->option('prune-orphans');

        // Этап 4: дельта считается только по FAQ-полосе. Чанки уроков живут в
        // той же таблице, но их ведет knowledge:index-lessons; без фильтра они
        // попали бы в $known, никогда не обновились бы и молча состарились.
        $known = DB::table('knowledge_chunks')
            ->where('source_type', KnowledgeChunk::SOURCE_FAQ)
            ->where('model', $model)
            ->where('dims', $dims)
            ->pluck('content_hash', 'faq_chunk_id');

        $orphans = $known->keys()
            ->diff(array_map(static fn ($chunk): string => $chunk->chunkId, $chunks))
            ->values()
            ->all();

        $driverOff = (string) config('knowledge.driver') === '';

        // Драйвер пуст, а удалять никто не просил: прежний честный отказ.
        // С `--prune-orphans` команда остается полезной и без эмбеддера —
        // уборка сирот не требует ни модели, ни туннеля.
        if ($driverOff && ! $prune) {
            $this->error('knowledge: dense leg disabled (KNOWLEDGE_EMBEDDING_DRIVER empty) — nothing to index');

            return self::FAILURE;
        }

        $this->handleOrphans($orphans, $model, $dims, $prune);

        if ($driverOff) {
            return self::SUCCESS;
        }

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

    /**
     * Сироты: строка полосы есть, ее chunk_id в корпусе больше нет.
     *
     * @param  list<string>  $orphans
     */
    private function handleOrphans(array $orphans, string $model, int $dims, bool $prune): void
    {
        if ($orphans === []) {
            if ($prune) {
                $this->info('knowledge: no orphan rows — таблица ровно по корпусу');
            }

            return;
        }

        if (! $prune) {
            $this->warn(sprintf(
                'knowledge: %d осиротевш(их) строк(и) в knowledge_chunks (%s, %d dims) — чанков с такими id в корпусе нет. '.
                'В ответы они не попадают, но плотная нога читает их из базы на каждом запросе. Убрать: knowledge:index --prune-orphans',
                count($orphans),
                $model,
                $dims,
            ));

            return;
        }

        // Удаляем ровно свою полосу: другой source_type (уроки) и чужая
        // модель/размерность не трогаются — иначе уборка FAQ вычистила бы
        // индекс уроков, собранный другой моделью.
        $deleted = DB::table('knowledge_chunks')
            ->where('source_type', KnowledgeChunk::SOURCE_FAQ)
            ->where('model', $model)
            ->where('dims', $dims)
            ->whereIn('faq_chunk_id', $orphans)
            ->delete();

        $this->info("knowledge: pruned {$deleted} orphan rows (of ".count($orphans).' detected)');
    }
}
