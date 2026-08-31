<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IAST → SLP1 transliteration — PHP port of the canonical sanskrit-util map
 * (sanskrit-util/py/sanskrit_util/__init__.py `_SLP1` / `to_slp1()`, H3762).
 *
 * Laravel has no runtime for sanskrit-util's js/ or py/ halves, so this is the
 * registered PHP-side consumer (SHARED_CODE.md row 79/80). Mirror the upstream
 * `_SLP1` table exactly on updates; never fork the map independently.
 *
 * Used server-side to build Cologne CDSL getword link-out keys from /slovar
 * IAST headwords. No behaviour beyond the upstream port except `keyFor()`,
 * which additionally decides whether the result is a *usable* Cologne key.
 */
final class IastToSlp1
{
    /**
     * Two-char digraphs first (aspirates, diphthongs), then single chars —
     * verbatim from sanskrit-util `_SLP1`.
     */
    private const SLP1 = [
        'ai' => 'E', 'au' => 'O', 'kh' => 'K', 'gh' => 'G', 'ch' => 'C', 'jh' => 'J', 'ṭh' => 'W', 'ḍh' => 'Q',
        'th' => 'T', 'dh' => 'D', 'ph' => 'P', 'bh' => 'B',
        'ā' => 'A', 'ī' => 'I', 'ū' => 'U', 'ṛ' => 'f', 'ṝ' => 'F', 'ḷ' => 'x', 'ḹ' => 'X',
        'ṃ' => 'M', 'ṁ' => 'M', 'ḥ' => 'H', 'ṅ' => 'N', 'ñ' => 'Y', 'ṭ' => 'w', 'ḍ' => 'q', 'ṇ' => 'R',
        'ś' => 'S', 'ṣ' => 'z', 'ḻ' => 'L',
        'a' => 'a', 'i' => 'i', 'u' => 'u', 'e' => 'e', 'o' => 'o', 'k' => 'k', 'g' => 'g', 'c' => 'c', 'j' => 'j',
        't' => 't', 'd' => 'd', 'n' => 'n', 'p' => 'p', 'b' => 'b', 'm' => 'm', 'y' => 'y', 'r' => 'r', 'l' => 'l',
        'v' => 'v', 's' => 's', 'h' => 'h',
    ];

    /** IAST → SLP1, longest-key-first so aspirates/diphthongs (kh, ai) map as one phoneme. */
    public static function toSlp1(string $iast): string
    {
        $out = '';
        $chars = preg_split('//u', $iast, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        for ($i = 0; $i < $n;) {
            if ($i + 1 < $n) {
                $two = $chars[$i].$chars[$i + 1];
                if (isset(self::SLP1[$two])) {
                    $out .= self::SLP1[$two];
                    $i += 2;

                    continue;
                }
            }
            $out .= self::SLP1[$chars[$i]] ?? $chars[$i];
            $i++;
        }

        return $out;
    }

    /**
     * A Cologne-usable SLP1 key for an IAST headword, or null when none can be
     * stood behind (emitting no link beats emitting a dead one — H839 ethic):
     *
     *  - NFC-normalise + trim + lower-case first (IAST proper-noun capitals like
     *    "Kṛṣṇa" would otherwise pass through as the WRONG SLP1 phoneme — 'K' is kh);
     *  - null when the converted key contains anything outside SLP1's ASCII-letter
     *    alphabet. That excludes multi-word headwords, hyphens, digits — and the
     *    avagraha «'», which reproducibly 500s Cologne's getword.php on every
     *    dictionary (H3762 probe, 30-08-2026; SHARED_CODE row 22 addendum).
     */
    public static function keyFor(?string $iast): ?string
    {
        $iast = trim((string) $iast);
        if ($iast === '') {
            return null;
        }
        if (class_exists(\Normalizer::class)) {
            $iast = \Normalizer::normalize($iast, \Normalizer::FORM_C) ?: $iast;
        }
        $key = self::toSlp1(mb_strtolower($iast));

        return preg_match('/\A[a-zA-Z]+\z/', $key) === 1 ? $key : null;
    }
}
