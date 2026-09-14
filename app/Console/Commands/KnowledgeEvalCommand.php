<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeChunk;
use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\HybridRetriever;
use Illuminate\Console\Command;

/**
 * H3234 (issue #1633 этап 2): knowledge:eval — recall@5 / MRR для конфигураций
 * BM25 (базовая линия H2448/H3766) и hybrid (H4001: BM25 ∪ dense, RRF поверх
 * knowledge_chunks) на золотом наборе. Приёмка эксперимента — hybrid ≥ BM25.
 *
 * Реюз, не дублирование: скоринг — те же Bm25FaqRetriever и HybridRetriever,
 * что работают в lane'е (H4001 собрал eval-гейты как тесты; команда даёт ту
 * же метрику в виде печатной таблицы для прогона на проде/с живым узлом).
 *
 * Dense-состояние определяется честно: если эмбеддер недоступен (драйвер не
 * задан / туннель умер / knowledge_chunks пуст), HybridRetriever сам держит
 * BM25-пол — таблица это покажет как равные числа, а не ложный выигрыш.
 */
class KnowledgeEvalCommand extends Command
{
    protected $signature = 'knowledge:eval
        {--evalset=tests/fixtures/faq_rag_eval.json : золотой набор с expected_chunk_ids}
        {--top-k=5 : recall@K / MRR}';

    protected $description = 'H3234: recall@K / MRR of BM25 vs hybrid retrieval on a golden eval set';

    public function handle(Bm25FaqRetriever $bm25, HybridRetriever $hybrid, EmbeddingProvider $embeddings): int
    {
        $evalsetPath = (string) $this->option('evalset');
        $full = str_starts_with($evalsetPath, '/') || preg_match('/^[A-Za-z]:/', $evalsetPath) === 1
            ? $evalsetPath
            : base_path($evalsetPath);
        if (! is_file($full)) {
            $this->error("no eval set: {$full}");

            return self::FAILURE;
        }

        $items = json_decode((string) file_get_contents($full), true)['items'] ?? null;
        if (! is_array($items) || $items === []) {
            $this->error('eval set has no items');

            return self::FAILURE;
        }

        $topK = max(1, (int) $this->option('top-k'));

        // Dense-состояние: один пробный embed. Пустой вектор = «dense-ноги
        // нет», исключение (узел недоступен, модели нет — контракт H4001
        // падает громко) — для таблицы честно трактуем как OFF, не crash.
        try {
            $denseOn = $embeddings->embed('проба живости') !== [];
        } catch (\Throwable) {
            $denseOn = false;
        }

        $indexed = KnowledgeChunk::query()->count();
        $this->info(sprintf(
            'eval items=%d top_k=%d | dense leg: %s (driver=%s, model=%s) | knowledge_chunks rows=%d',
            count($items),
            $topK,
            $denseOn ? 'ON' : 'OFF (BM25 floor)',
            (string) config('knowledge.driver', ''),
            (string) config('knowledge.embedding_model', ''),
            $indexed,
        ));

        if (! $denseOn) {
            $this->warn('dense-нога недоступна: KNOWLEDGE_EMBEDDING_DRIVER=ollama и живой туннель дадут настоящую гибридную ногу.');
        }

        // Гибридная конфигурация замеряется с включёнными флагами lane'а —
        // eval-команда измеряет МЕХАНИКУ, а не состояние прод-флагов. Но
        // включаем их ТОЛЬКО при живой dense-ноге: их HybridRetriever при
        // выключенных флагах держит BM25-пол, а броски OllamaEmbeddingProvider
        // (узел умер) в середине прогона сорвали бы таблицу — при мёртвой
        // ноге честнее замерить пол и пометить его в шапке.
        $prevSuggester = config('features.faq_rag_suggester');
        $prevHybrid = config('features.faq_hybrid_retrieval');
        config([
            'features.faq_rag_suggester' => true,
            'features.faq_hybrid_retrieval' => $denseOn,
        ]);

        try {
            $rows = [];
            foreach (['bm25' => 'BM25 (faq.md)', 'hybrid' => 'Hybrid (RRF + dense)'] as $config => $label) {
                $hits = 0;
                $rrSum = 0.0;

                foreach ($items as $item) {
                    $expected = array_values(array_map('strval', $item['expected_chunk_ids'] ?? []));
                    if ($expected === []) {
                        continue;
                    }

                    $retrieved = $config === 'bm25'
                        ? array_map(
                            static fn (array $h): string => $h['chunk']->chunkId,
                            $bm25->retrieveChunks((string) $item['question'], $topK),
                        )
                        : array_map(
                            static fn (array $h): string => $h['chunk']->chunkId,
                            $hybrid->retrieveChunks((string) $item['question'], $topK),
                        );

                    $rank = $this->firstRelevantRank($retrieved, $expected);
                    if ($rank !== null) {
                        $hits++;
                        $rrSum += 1.0 / $rank;
                    }
                }

                $n = max(1, count($items));
                $rows[] = [
                    'config' => $label,
                    'questions' => count($items),
                    "recall@{$topK}" => round($hits / $n, 4),
                    'MRR' => round($rrSum / $n, 4),
                ];
            }
        } finally {
            config([
                'features.faq_rag_suggester' => $prevSuggester,
                'features.faq_hybrid_retrieval' => $prevHybrid,
            ]);
        }

        $this->table(
            ['config', 'questions', "recall@{$topK}", 'MRR'],
            $rows,
        );

        $recallKey = "recall@{$topK}";
        if ($rows[1][$recallKey] >= $rows[0][$recallKey]) {
            $this->info('acceptance: hybrid ≥ BM25 — OK');
        } else {
            $this->warn('acceptance: hybrid < BM25 — приёмка НЕ пройдена (пол H4001 нарушен: так не должно быть)');
        }

        return self::SUCCESS;
    }

    /**
     * Ранг (с 1) первого ожидаемого чанка в выдаче или null. Суффикс-фолбэк —
     * чанки из дополнительных корпусов несут префикс файла (faq_from_lectures/…).
     *
     * @param  list<string>  $retrieved
     * @param  list<string>  $expected
     */
    private function firstRelevantRank(array $retrieved, array $expected): ?int
    {
        foreach ($retrieved as $i => $chunkId) {
            foreach ($expected as $exp) {
                if ($chunkId === $exp || str_ends_with($chunkId, '/'.$exp)) {
                    return $i + 1;
                }
            }
        }

        return null;
    }
}
