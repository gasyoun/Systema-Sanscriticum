<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Математика blinded-ревью исходящих ответов (support:replies-review):
 * ok-share по решающим строкам (na исключён, но публикуется), Wilson 95 % CI.
 * Только целые счётчики и доли — текстов не касается.
 */
final class RepliesReviewStats
{
    /**
     * Wilson score interval (95 %, z = 1.96) для k успехов из n.
     *
     * @return array{0: float, 1: float}|null [lo, hi]
     */
    public static function wilson95(int $k, int $n): ?array
    {
        if ($n <= 0) {
            return null;
        }
        $z = 1.96;
        $p = $k / $n;
        $denom = 1 + $z * $z / $n;
        $center = ($p + $z * $z / (2 * $n)) / $denom;
        $half = ($z / $denom) * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n));

        return [
            round(max(0.0, $center - $half), 4),
            round(min(1.0, $center + $half), 4),
        ];
    }

    /**
     * @param  list<string>  $verdicts  ok|minor|wrong|na
     * @return array<string, int|float|array|null>
     */
    public static function summarize(array $verdicts): array
    {
        $counts = array_fill_keys(['ok', 'minor', 'wrong', 'na'], 0);
        foreach ($verdicts as $verdict) {
            if (isset($counts[$verdict])) {
                $counts[$verdict]++;
            }
        }
        $decisive = $counts['ok'] + $counts['minor'] + $counts['wrong'];

        return [
            'n' => count($verdicts),
            'ok' => $counts['ok'],
            'minor' => $counts['minor'],
            'wrong' => $counts['wrong'],
            'na' => $counts['na'],
            'n_decisive' => $decisive,
            'ok_share' => $decisive > 0 ? round($counts['ok'] / $decisive, 4) : null,
            'ok_plus_minor_share' => $decisive > 0
                ? round(($counts['ok'] + $counts['minor']) / $decisive, 4)
                : null,
            'wilson95' => self::wilson95($counts['ok'], $decisive),
        ];
    }
}
