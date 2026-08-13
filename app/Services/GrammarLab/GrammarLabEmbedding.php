<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

/**
 * Query-side twin of SanskritGrammar scripts/grammar_lab_embed.py
 * charngram-hash-v1 (384-d signed hashed trigrams, L2-normalized).
 *
 * G1 shipped semantic_ready=false. This sidecar exists so G2 can prove
 * parity and score cosine when a later rebuild flips semantic_ready.
 * features.grammar_lab_semantic stays OFF until that AND Recall@5 pass.
 */
final class GrammarLabEmbedding
{
    public const ALGO = 'charngram-hash-v1';

    public const DIM = 384;

    /**
     * @return list<float>
     */
    public static function hashProject(string $text, int $dim = self::DIM): array
    {
        $folded = preg_replace('/\s+/u', ' ', mb_strtolower($text, 'UTF-8')) ?? $text;
        $folded = trim($folded);
        $padded = '  '.$folded.'  ';
        $len = mb_strlen($padded, 'UTF-8');
        $vec = array_fill(0, $dim, 0.0);
        if ($len < 3) {
            return $vec;
        }

        for ($i = 0; $i <= $len - 3; $i++) {
            $gram = mb_substr($padded, $i, 3, 'UTF-8');
            $digest = hash('sha256', self::ALGO.':'.$gram, true);
            $idx = unpack('v', substr($digest, 0, 2))[1] % $dim;
            $sign = (ord($digest[2]) % 2 === 0) ? 1.0 : -1.0;
            $vec[$idx] += $sign;
        }

        $norm = 0.0;
        foreach ($vec as $x) {
            $norm += $x * $x;
        }
        $norm = sqrt($norm) ?: 1.0;
        foreach ($vec as $i => $x) {
            $vec[$i] = round($x / $norm, 8);
        }

        return $vec;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        $denom = sqrt($na) * sqrt($nb);

        return $denom > 0.0 ? $dot / $denom : 0.0;
    }

    public static function sourceText(array $topic): string
    {
        $aliases = $topic['aliases'] ?? [];
        $parts = [
            (string) ($topic['title_ru'] ?? ''),
            (string) ($topic['summary_ru'] ?? ''),
            implode(' ', $aliases['ru'] ?? []),
            implode(' ', $aliases['deva'] ?? []),
            implode(' ', $aliases['iast'] ?? []),
            implode(' ', $aliases['slp1'] ?? []),
        ];

        return trim(implode(' ', array_filter($parts, fn ($p) => $p !== '')));
    }
}
