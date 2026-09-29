<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\HybridRetriever;
use App\Services\Support\Faq\KnowledgeVectors;
use App\Services\Support\Faq\RuTextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Этап 4 — поиск по расшифровкам, СТРОГО в пределах доступа студента.
 *
 * Фильтр доступа стоит в самом запросе (`whereIn('lesson_id', …)`), а не после
 * ранжирования: чужой фрагмент не должен даже попадать в память процесса,
 * который потом собирает промпт. Он же держит выборку маленькой — у студента
 * это десятки фрагментов, а не весь индекс.
 *
 * Слияние — тот же weighted RRF, что у {@see HybridRetriever},
 * но веса зеркальные (config knowledge.lesson.*): у FAQ лексическая нога —
 * пол, а на дословной устной речи она шумит, поэтому здесь ведёт плотная нога.
 * Замер 16-09-2026 на расшифровке урока 1961: чистый вектор не поднимал
 * лексически очевидный фрагмент, а BM25 с весом выше вектора вытаскивал
 * болтовню из начала записи.
 *
 * Недоступна dense-нога (туннель лёг) — деградируем в чистый BM25, как H4416.
 */
final class LessonRetriever
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly AccessibleLessonIds $accessible,
        private readonly RuTextNormalizer $normalizer = new RuTextNormalizer,
    ) {}

    /**
     * @param  list<int>|null  $limitToLessons  закреплённый урок/курс, если студент сузил область
     * @return list<array{chunk: KnowledgeChunk, lesson: Lesson, score: float, cos: float, bm25: float}>
     */
    public function retrieve(User $user, string $query, ?array $limitToLessons = null, ?int $topK = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $allowed = $this->accessible->forUser($user);
        if ($limitToLessons !== null) {
            $allowed = array_values(array_intersect($allowed, $limitToLessons));
        }
        if ($allowed === []) {
            return [];
        }

        $rows = KnowledgeChunk::query()
            ->where('source_type', KnowledgeChunk::SOURCE_LESSON)
            ->where('model', (string) config('knowledge.embedding_model', 'bge-m3'))
            ->where('dims', (int) config('knowledge.dimensions', 1024))
            ->whereIn('lesson_id', $allowed)
            ->get(['id', 'faq_chunk_id', 'lesson_id', 'course_id', 'start_seconds', 'text', 'embedding']);

        if ($rows->isEmpty()) {
            return [];
        }

        $topK = max(1, $topK ?? (int) config('knowledge.lesson.top_k', 6));
        $depth = max($topK, (int) config('knowledge.fusion.depth', 20));

        $dense = $this->denseLeg($query, $rows);
        $sparse = $this->sparseLeg($query, $rows);

        $fused = $this->fuse($rows->keyBy('id'), $dense, $sparse, $depth, $topK);
        if ($fused === []) {
            return [];
        }

        $lessons = Lesson::query()
            ->with('course')
            ->whereIn('id', array_values(array_unique(array_map(
                static fn (array $hit): int => (int) $hit['chunk']->lesson_id,
                $fused,
            ))))
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($fused as $hit) {
            $lesson = $lessons->get((int) $hit['chunk']->lesson_id);
            if (! $lesson instanceof Lesson) {
                continue;
            }
            $out[] = [
                'chunk' => $hit['chunk'],
                'lesson' => $lesson,
                'score' => $hit['score'],
                'cos' => $hit['cos'],
                'bm25' => $hit['bm25'],
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, KnowledgeChunk>  $rows
     * @return array<int, float> chunk row id => cosine
     */
    private function denseLeg(string $query, $rows): array
    {
        try {
            $vector = $this->embeddings->embed($query);
        } catch (Throwable $e) {
            Log::warning('LessonRetriever: dense-нога недоступна, деградация в BM25', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($vector === []) {
            return [];
        }

        $scores = [];
        foreach ($rows as $row) {
            $scores[(int) $row->id] = KnowledgeVectors::cosine($vector, KnowledgeVectors::unpack((string) $row->embedding));
        }
        arsort($scores, SORT_NUMERIC);

        return $scores;
    }

    /**
     * Okapi BM25 (K1=1.5 / B=0.75 — константы Bm25FaqRetriever) поверх текста
     * фрагмента, с той же русской нормализацией, что у FAQ-ноги.
     *
     * @param  Collection<int, KnowledgeChunk>  $rows
     * @return array<int, float> chunk row id => bm25
     */
    private function sparseLeg(string $query, $rows): array
    {
        $k1 = 1.5;
        $b = 0.75;

        $docs = [];
        foreach ($rows as $row) {
            $docs[(int) $row->id] = $this->tokenize((string) $row->text);
        }

        $n = count($docs);
        $avgdl = $n > 0 ? array_sum(array_map('count', $docs)) / $n : 0.0;
        if ($avgdl <= 0.0) {
            return [];
        }

        $df = [];
        foreach ($docs as $tokens) {
            foreach (array_unique($tokens) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }

        $queryTerms = $this->normalizer->expandQuery($this->tokenize($query));

        $scores = [];
        foreach ($docs as $id => $tokens) {
            $tf = array_count_values($tokens);
            $dl = count($tokens);
            $score = 0.0;
            foreach ($queryTerms as $term) {
                $f = $tf[$term] ?? 0;
                if ($f === 0) {
                    continue;
                }
                $idf = log(1 + (($n - $df[$term] + 0.5) / ($df[$term] + 0.5)));
                $score += $idf * (($f * ($k1 + 1)) / ($f + $k1 * (1 - $b + $b * ($dl / $avgdl))));
            }
            if ($score > 0.0) {
                $scores[$id] = $score;
            }
        }
        arsort($scores, SORT_NUMERIC);

        return $scores;
    }

    /** @return list<string> */
    private function tokenize(string $text): array
    {
        $folded = $this->normalizer->fold($text);
        $parts = preg_split('/[^\p{L}\p{Nd}]+/u', $folded) ?: [];

        $out = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 3) {
                continue;
            }
            $out[] = $this->normalizer->stem($part);
        }

        return $out;
    }

    /**
     * @param  Collection<int, KnowledgeChunk>  $rowsById
     * @param  array<int, float>  $dense
     * @param  array<int, float>  $sparse
     * @return list<array{chunk: KnowledgeChunk, score: float, cos: float, bm25: float}>
     */
    private function fuse($rowsById, array $dense, array $sparse, int $depth, int $topK): array
    {
        $k = max(1, (int) config('knowledge.fusion.k', 60));
        $wDense = max(0.0, (float) config('knowledge.lesson.weight_dense', 1.0));
        $wSparse = max(0.0, (float) config('knowledge.lesson.weight_sparse', 0.6));

        $fused = [];
        $rank = 0;
        foreach ($dense as $id => $score) {
            if ($rank >= $depth) {
                break;
            }
            $fused[$id] = ($fused[$id] ?? 0.0) + $wDense / ($k + $rank + 1);
            $rank++;
        }

        // Лексические попадания слабее четверти лучшего отбрасываем: на устной
        // речи хвост BM25 почти случаен и перебивал плотную ногу (16-09-2026).
        $bestSparse = $sparse === [] ? 0.0 : (float) reset($sparse);
        $floor = $bestSparse * 0.25;
        $rank = 0;
        foreach ($sparse as $id => $score) {
            if ($rank >= $depth) {
                break;
            }
            if ($score >= $floor && $score > 0.0) {
                $fused[$id] = ($fused[$id] ?? 0.0) + $wSparse / ($k + $rank + 1);
            }
            $rank++;
        }

        arsort($fused, SORT_NUMERIC);

        $out = [];
        foreach (array_slice($fused, 0, $topK, true) as $id => $score) {
            $row = $rowsById->get($id);
            if (! $row instanceof KnowledgeChunk) {
                continue;
            }
            $out[] = [
                'chunk' => $row,
                'score' => (float) $score,
                'cos' => (float) ($dense[$id] ?? 0.0),
                'bm25' => (float) ($sparse[$id] ?? 0.0),
            ];
        }

        return $out;
    }
}
