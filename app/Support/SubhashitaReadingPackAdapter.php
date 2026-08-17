<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H2168 — normalise the frozen `subhashita-beginner` pack onto the ONE existing
 * reading-pack contract (reading_pack_v1) the reader already renders.
 *
 * The freeze (kosha H2165, `data/nala_subhashita/MANIFEST.json`) pins this pack
 * with an explicit adapter note: its shape is `sayings[]` with
 * `lines[].chunks[]` triples (`t`/`lemma_slp1`/`gloss_ru`) and a German
 * translation, NOT reading_pack_v1's `sentences[].tokens[]` — "must adapt or
 * normalize — do not invent a second schema silently".
 *
 * So this class adapts, in memory, at read time:
 *
 *  - the vendored file on disk stays BYTE-IDENTICAL to the freeze pin, which is
 *    what keeps `scripts/vendor_nala_subhashita_packs.py --check` meaningful. A
 *    normalised copy written to disk could never be verified against the freeze
 *    again;
 *  - nothing downstream learns a second shape. The Blade partial
 *    `reading.partials.pack`, `App\Services\StartChteniyaProgress` and the SRS
 *    card writer all keep consuming reading_pack_v1 only.
 *
 * Mapping (one sāying -> one "sentence"; chunk tokens flattened in order):
 *
 *  | reading_pack_v1        | subhashita_reader_pack                            |
 *  |------------------------|---------------------------------------------------|
 *  | sentences[].locus      | "Subhāṣita <num>" + metre when the pack knows one |
 *  | sentences[].text       | saying.iast (the `/` line separator spaced out)   |
 *  | tokens[].form          | chunks[].t[i] (the split, post-sandhi word)       |
 *  | tokens[].lemma/slp1    | chunks[].lemma_slp1[i] (SLP1; no IAST lemma here) |
 *  | tokens[].gloss_ru      | chunks[].gloss_ru[i] ({surface,lemma,root})       |
 *  | tokens[].morph/gloss   | absent in this feed -> omitted, never fabricated   |
 *
 * A token whose `lemma_slp1` slot is null stays lemma-less on purpose: the
 * reader already degrades for such tokens (no disclosure lemma, no SRS button)
 * rather than inventing one.
 */
final class SubhashitaReadingPackAdapter
{
    /**
     * @param  array<string,mixed>  $raw  the pack exactly as frozen
     * @return array<string,mixed> reading_pack_v1
     */
    public static function toReadingPackV1(array $raw): array
    {
        $sentences = [];
        $tokenCount = 0;
        $linkedCount = 0;

        foreach (($raw['sayings'] ?? []) as $saying) {
            $tokens = [];

            foreach (($saying['lines'] ?? []) as $line) {
                foreach (($line['chunks'] ?? []) as $chunk) {
                    foreach (($chunk['t'] ?? []) as $i => $form) {
                        $lemma = $chunk['lemma_slp1'][$i] ?? null;
                        $lemma = is_string($lemma) ? trim($lemma) : '';

                        $token = [
                            'form' => (string) $form,
                            'lemma' => $lemma,
                            'slp1' => $lemma,
                        ];

                        $glossRu = $chunk['gloss_ru'][$i] ?? null;
                        if (is_array($glossRu) && $glossRu !== []) {
                            $token['gloss_ru'] = $glossRu;
                        }

                        $tokens[] = $token;
                        $tokenCount++;
                        if ($lemma !== '') {
                            $linkedCount++;
                        }
                    }
                }
            }

            $sentences[] = [
                'n' => (string) ($saying['num'] ?? ''),
                'locus' => self::locus($saying),
                'text' => self::text($saying),
                'tokens' => $tokens,
            ];
        }

        return [
            'slug' => (string) ($raw['slug'] ?? 'subhashita-beginner'),
            'title' => (string) ($raw['title'] ?? 'Subhāṣita'),
            'ref' => 'Indische Sprüche',
            'text_name' => 'Subhāṣita',
            'source' => (string) ($raw['source'] ?? ''),
            'built' => (string) ($raw['built'] ?? ''),
            'stats' => [
                'sentences' => count($sentences),
                'tokens' => $tokenCount,
                'linked_tokens' => $linkedCount,
                'link_rate_pct' => $tokenCount === 0
                    ? 0.0
                    : round($linkedCount / $tokenCount * 100, 1),
            ],
            'sentences' => $sentences,
        ];
    }

    /** @param array<string,mixed> $saying */
    private static function locus(array $saying): string
    {
        $locus = 'Subhāṣita '.($saying['num'] ?? '');
        $metre = $saying['metre'] ?? null;

        if (is_string($metre) && $metre !== '') {
            $locus .= ' · '.$metre;
        }

        return trim($locus);
    }

    /**
     * The pack stores both hemistichs of a saying in one `iast` string joined by
     * `/`. Space it out so the line break reads as a break, without touching the
     * daṇḍa marks the source itself carries.
     *
     * @param  array<string,mixed>  $saying
     */
    private static function text(array $saying): string
    {
        $iast = (string) ($saying['iast'] ?? '');

        return trim(preg_replace('/\s*\/\s*/u', ' ', $iast) ?? $iast);
    }
}
