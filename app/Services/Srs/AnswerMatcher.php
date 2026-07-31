<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\SrsCard;

/**
 * Typing-mode answer check (Memrise P2 + P2b cheapest path: romanized-in + normalize).
 * Exact (case/space-insensitive) → match; near-miss Levenshtein → soft match.
 */
final class AnswerMatcher
{
    public const EXACT = 'exact';

    public const SOFT = 'soft';

    public const MISS = 'miss';

    /**
     * @return self::EXACT|self::SOFT|self::MISS
     */
    public function match(SrsCard $card, string $typed): string
    {
        $typedNorm = $this->normalize($typed);
        if ($typedNorm === '') {
            return self::MISS;
        }

        $best = self::MISS;
        foreach (CardFace::acceptableAnswers($card) as $candidate) {
            $candNorm = $this->normalize($candidate);
            if ($candNorm === '') {
                continue;
            }
            if ($typedNorm === $candNorm) {
                return self::EXACT;
            }
            if ($this->isSoftMatch($typedNorm, $candNorm)) {
                $best = self::SOFT;
            }
        }

        return $best;
    }

    public function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // Collapse internal whitespace; strip common punctuation noise.
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[.,;:!?«»"\'()]+/u', '', $s) ?? $s;

        // ё → е (Russian typing).
        $s = str_replace('ё', 'е', $s);

        return $s;
    }

    private function isSoftMatch(string $a, string $b): bool
    {
        $maxLen = max(mb_strlen($a), mb_strlen($b));
        if ($maxLen === 0 || $a === $b) {
            return false;
        }
        // Allow 1 edit for short strings, 2 for longer.
        // PHP levenshtein() is byte-wise and mis-counts UTF-8 (Cyrillic/Devanagari).
        $threshold = $maxLen <= 6 ? 1 : 2;
        $dist = $this->mbLevenshtein($a, $b);

        return $dist > 0 && $dist <= $threshold;
    }

    /** Codepoint-aware Levenshtein for short student answers. */
    private function mbLevenshtein(string $a, string $b): int
    {
        $aa = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bb = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $m = count($aa);
        $n = count($bb);
        if ($m === 0) {
            return $n;
        }
        if ($n === 0) {
            return $m;
        }

        $prev = range(0, $n);
        for ($i = 0; $i < $m; $i++) {
            $curr = [$i + 1];
            for ($j = 0; $j < $n; $j++) {
                $cost = $aa[$i] === $bb[$j] ? 0 : 1;
                $curr[] = min(
                    $curr[$j] + 1,
                    $prev[$j + 1] + 1,
                    $prev[$j] + $cost,
                );
            }
            $prev = $curr;
        }

        return $prev[$n];
    }
}
