<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Top-N ranking over ClipSpanPlanner's output (H1547, Wave 1). Ranking only —
 * never recomputes span boundaries. Heuristic-only for now (D12 default);
 * an optional CuratorAi re-rank pass is future work behind
 * `features.content_from_lectures` + a bound API client, deliberately not
 * wired here so tests stay deterministic without a fake/bound client.
 *
 * Score = title keyword richness + mid-lesson position preference + duration
 * closeness to the [45, 120]s sweet spot.
 */
final class SpanRanker
{
    private const DURATION_MIN = 45;

    private const DURATION_MAX = 120;

    /**
     * @param  list<array{start_seconds:int,end_seconds:int,title:string}>  $spans
     * @return list<array{start_seconds:int,end_seconds:int,title:string}>
     */
    public static function rank(array $spans, ?int $n = null): array
    {
        $n ??= (int) config('content.clip_rank_n', 5);

        if ($spans === []) {
            return [];
        }

        $lastEnd = end($spans)['end_seconds'];
        $firstStart = $spans[0]['start_seconds'];
        $totalDuration = max(1, $lastEnd - $firstStart);

        $scored = array_map(
            static fn (array $span, int $index): array => [
                'span' => $span,
                'index' => $index,
                'score' => self::score($span, $firstStart, $totalDuration),
            ],
            $spans,
            array_keys($spans),
        );

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: $a['index'] <=> $b['index']);

        $top = array_slice($scored, 0, max(0, $n));

        // Re-sort by original order (chronological) so n8n receives spans in
        // playback order — ranking picks *which* spans, not their sequence.
        usort($top, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_values(array_map(static fn (array $row): array => $row['span'], $top));
    }

    /**
     * @param  array{start_seconds:int,end_seconds:int,title:string}  $span
     */
    private static function score(array $span, int $firstStart, int $totalDuration): float
    {
        $duration = max(0, $span['end_seconds'] - $span['start_seconds']);
        $durationScore = self::durationScore($duration);

        $midpoint = $span['start_seconds'] + $duration / 2;
        $positionRatio = ($midpoint - $firstStart) / $totalDuration;
        // Mid-lesson preference: a bell-shaped bump around ratio 0.5, 0 at the edges.
        $positionScore = 1.0 - abs($positionRatio - 0.5) * 2;

        $keywordScore = self::keywordRichness($span['title']);

        return $durationScore * 0.4 + $positionScore * 0.3 + $keywordScore * 0.3;
    }

    private static function durationScore(int $duration): float
    {
        if ($duration >= self::DURATION_MIN && $duration <= self::DURATION_MAX) {
            return 1.0;
        }

        $distance = $duration < self::DURATION_MIN
            ? self::DURATION_MIN - $duration
            : $duration - self::DURATION_MAX;

        return max(0.0, 1.0 - $distance / self::DURATION_MAX);
    }

    /** Richer, more distinct title text scores higher — a crude content-density proxy. */
    private static function keywordRichness(string $title): float
    {
        $words = preg_split('/\s+/u', trim($title), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return 0.0;
        }

        $unique = count(array_unique(array_map('mb_strtolower', $words)));

        return min(1.0, $unique / 12);
    }
}
