<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Models\GrammarTopic;
use App\Support\GrammarLabAccess;

/**
 * Offline hybrid retrieval over imported Grammar Lab topics (H2493).
 *
 * Lexical (exact/prefix alias + Okapi BM25) is always available.
 * Vector cosine is applied only when features.grammar_lab_semantic is ON
 * AND the vendored topic_vectors.json has semantic_ready=true.
 */
final class GrammarLabSearch
{
    private const K1 = 1.5;

    private const B = 0.75;

    /**
     * @return list<array{
     *   topic_id: string,
     *   slug: string,
     *   title_ru: string,
     *   snippet: string,
     *   sources: list<string>,
     *   score: float,
     *   lexical: float,
     *   vector: float,
     *   match: string
     * }>
     */
    public function search(string $query, int $topK = 10, ?bool $semantic = null): array
    {
        $queryFold = GrammarLabNormalizer::fold($query);
        $queryTokens = GrammarLabNormalizer::tokens($query);
        if ($queryFold === '' && $queryTokens === []) {
            return [];
        }

        $topics = GrammarTopic::query()
            ->where('status', 'published')
            ->with('sources')
            ->get();
        if ($topics->isEmpty()) {
            return [];
        }

        $docs = [];
        $df = [];
        $totalLen = 0;
        foreach ($topics as $i => $topic) {
            $tokens = GrammarLabNormalizer::tokens($this->searchText($topic));
            $tf = array_count_values($tokens);
            $len = max(1, count($tokens));
            $docs[$i] = ['topic' => $topic, 'tf' => $tf, 'len' => $len, 'tokens' => $tokens];
            $totalLen += $len;
            foreach (array_keys($tf) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }

        $n = count($docs);
        $avgdl = $totalLen / max(1, $n);
        $useSemantic = $semantic ?? $this->semanticAvailable();
        $queryVec = $useSemantic ? GrammarLabEmbedding::hashProject($this->queryEmbedText($query)) : [];
        $vectors = $useSemantic ? $this->loadVectors() : [];

        $fusion = config('grammar_lab.fusion', []);
        $lexW = (float) ($fusion['lexical_weight'] ?? 0.70);
        $vecW = (float) ($fusion['vector_weight'] ?? 0.30);
        $exactBoost = (float) ($fusion['exact_alias_boost'] ?? 8.0);
        $prefixBoost = (float) ($fusion['prefix_alias_boost'] ?? 3.0);

        $scored = [];
        foreach ($docs as $i => $doc) {
            /** @var GrammarTopic $topic */
            $topic = $doc['topic'];
            $bm25 = 0.0;
            foreach ($queryTokens as $term) {
                $freq = $doc['tf'][$term] ?? 0;
                if ($freq === 0) {
                    continue;
                }
                $nQi = $df[$term] ?? 0;
                $idf = log((($n - $nQi + 0.5) / ($nQi + 0.5)) + 1.0);
                $denom = $freq + self::K1 * (1.0 - self::B + self::B * ($doc['len'] / $avgdl));
                $bm25 += $idf * (($freq * (self::K1 + 1.0)) / $denom);
            }

            $match = $this->aliasMatch($topic, $queryFold);
            $lexical = $bm25;
            if ($match === 'exact') {
                $lexical += $exactBoost;
            } elseif ($match === 'prefix') {
                $lexical += $prefixBoost;
            }

            $vector = 0.0;
            if ($useSemantic && $queryVec !== []) {
                $stored = $vectors[$topic->topic_id] ?? null;
                if (is_array($stored)) {
                    $vector = GrammarLabEmbedding::cosine($queryVec, $stored);
                }
            }

            $score = $useSemantic
                ? ($lexW * $lexical) + ($vecW * max(0.0, $vector) * 10.0)
                : $lexical;

            if ($score <= 0.0) {
                continue;
            }

            $scored[] = [
                'topic_id' => $topic->topic_id,
                'slug' => $topic->slug,
                'title_ru' => $topic->title_ru,
                'snippet' => $this->snippet($topic),
                'sources' => $this->sourceLabels($topic),
                'score' => round($score, 4),
                'lexical' => round($lexical, 4),
                'vector' => round($vector, 4),
                'match' => $match,
            ];
        }

        usort($scored, function (array $a, array $b): int {
            $cmp = $b['score'] <=> $a['score'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['topic_id'], $b['topic_id']);
        });

        return array_slice($scored, 0, max(1, $topK));
    }

    public function semanticAvailable(): bool
    {
        if (! GrammarLabAccess::semanticOn()) {
            return false;
        }
        $meta = $this->vectorMeta();

        return (bool) ($meta['semantic_ready'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function vectorMeta(): array
    {
        $path = resource_path(config('grammar_lab.vendor_rel', 'data/grammar_lab').'/topic_vectors.json');
        if (! is_file($path)) {
            return ['semantic_ready' => false];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : ['semantic_ready' => false];
    }

    /**
     * @return array<string, list<float>>
     */
    private function loadVectors(): array
    {
        $meta = $this->vectorMeta();
        $out = [];
        foreach ($meta['vectors'] ?? [] as $row) {
            if (is_array($row) && isset($row['id'], $row['vector']) && is_array($row['vector'])) {
                $out[(string) $row['id']] = $row['vector'];
            }
        }

        return $out;
    }

    private function searchText(GrammarTopic $topic): string
    {
        $aliases = $topic->aliases ?? [];
        $parts = [
            $topic->title_ru,
            $topic->summary_ru,
            $topic->cluster ?? '',
            implode(' ', $aliases['ru'] ?? []),
            implode(' ', $aliases['deva'] ?? []),
            implode(' ', $aliases['iast'] ?? []),
            implode(' ', $aliases['slp1'] ?? []),
        ];

        return implode(' ', $parts);
    }

    private function queryEmbedText(string $query): string
    {
        return $query;
    }

    private function aliasMatch(GrammarTopic $topic, string $queryFold): string
    {
        if ($queryFold === '') {
            return 'none';
        }
        $surfaces = [GrammarLabNormalizer::fold($topic->title_ru)];
        foreach ($topic->aliases ?? [] as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $alias) {
                $surfaces[] = GrammarLabNormalizer::fold((string) $alias);
            }
        }
        foreach ($surfaces as $surface) {
            if ($surface !== '' && $surface === $queryFold) {
                return 'exact';
            }
        }
        foreach ($surfaces as $surface) {
            if ($surface !== '' && (str_starts_with($surface, $queryFold) || str_starts_with($queryFold, $surface))) {
                return 'prefix';
            }
        }

        return 'none';
    }

    private function snippet(GrammarTopic $topic): string
    {
        $text = trim((string) $topic->summary_ru);
        if (mb_strlen($text, 'UTF-8') <= 220) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 217, 'UTF-8')).'…';
    }

    /**
     * @return list<string>
     */
    private function sourceLabels(GrammarTopic $topic): array
    {
        $labels = [];
        foreach ($topic->sources as $source) {
            $labels[] = $source->family === 'whitney' ? 'Whitney' : ($source->family === 'zalizniak' ? 'Зализняк' : $source->anchor_type);
        }

        return array_values(array_unique($labels));
    }
}
