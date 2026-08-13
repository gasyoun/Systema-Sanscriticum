<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

/**
 * Shared fold for Grammar Lab lexical search (H2493).
 * NFC + Unicode casefold-equivalent + whitespace collapse.
 * Does not invent a new Sanskrit transcoder: aliases already carry
 * RU / Devanāgarī / IAST / SLP1, so search matches those surfaces.
 */
final class GrammarLabNormalizer
{
    public static function fold(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $nfc = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($nfc) && $nfc !== '') {
                $text = $nfc;
            }
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $folded = self::fold($text);
        if ($folded === '') {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $folded, $m);
        $out = [];
        foreach ($m[0] ?? [] as $token) {
            if (mb_strlen($token, 'UTF-8') < 1) {
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }
}
