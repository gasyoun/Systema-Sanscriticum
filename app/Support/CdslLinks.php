<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cologne CDSL entry-display link-out for /slovar pages (H3762, wave 1).
 *
 * URL shape is the canonical one from SHARED_CODE.md row 22 / csl-atlas
 * scripts/lib/cologne-links.mjs entryUrl() — a real deep link (main_webtc.js
 * fires getWord() on ready when `key` is non-empty), NOT a pre-filled form:
 *
 *   https://www.sanskrit-lexicon.uni-koeln.de/scans/{DICT}Scan/2020/web/webtc/
 *     indexcaller.php?key={slp1}&transLit=slp1&filter=roman
 *
 * Wave 1 scope (MG ruling 30-08-2026): link-out only — no content fetching,
 * no new tables, no sync jobs. MW at minimum; further dictionaries where the
 * SLP1 key resolves. The URL resolves a HEADWORD, not one record — homonyms
 * all come back; a headword genuinely absent from a dictionary lands on its
 * "not found" page, which is distinct from a dead link (see DICTS on AP90).
 */
final class CdslLinks
{
    /**
     * Cologne scan-dir token + human label, keyed by csl-orig dict code.
     *
     * AP90 is deliberately ABSENT from wave 1: its getword keys are the printed
     * NOMINATIVE headwords (agniH, yogaH — live-verified 31-08-2026), not bare
     * stems, so stem-keyed links land on "not found" for most nouns. Deriving
     * the nominative needs gender/stem-class data /slovar does not hold; never
     * ship known-dead links (H3762 acceptance). MW and PWG are stem-keyed.
     */
    private const DICTS = [
        'mw' => ['dir' => 'MW', 'label' => 'Monier-Williams (MW)'],
        'pwg' => ['dir' => 'PWG', 'label' => 'Бётлингк–Рот (PWG)'],
    ];

    private const BASE = 'https://www.sanskrit-lexicon.uni-koeln.de/scans';

    /**
     * Link-out rows for an IAST headword: [['code','label','url'], ...].
     * Empty when no Cologne-usable SLP1 key exists (see IastToSlp1::keyFor()).
     *
     * @return array<int, array{code: string, label: string, url: string}>
     */
    public static function forIast(?string $iast): array
    {
        $key = IastToSlp1::keyFor(self::headword($iast));
        if ($key === null) {
            return [];
        }

        $links = [];
        foreach (self::DICTS as $code => $dict) {
            $links[] = [
                'code' => $code,
                'label' => $dict['label'],
                'url' => self::BASE.'/'.$dict['dir'].'Scan/2020/web/webtc/indexcaller.php?key='
                    .rawurlencode($key).'&transLit=slp1&filter=roman',
            ];
        }

        return $links;
    }

    /**
     * The slovar `iast` column carries dictionary formatting, not a bare headword
     * (measured over all 11,726 prod rows, 31-08-2026 — this cleanup lifts key
     * resolution from 86.2% to 99.4%):
     *
     *  - Kochergina-style «/headword/» wrapper;
     *  - variant lists — take the first variant («duṣyanta, duḥṣanta», «x/ /y»);
     *  - bound forms and compound division («-akṣa», «sam-», «deva-datta») — the
     *    hyphen-free stem is the CDSL headword.
     */
    private static function headword(?string $iast): string
    {
        $w = trim((string) $iast);
        if (str_starts_with($w, '/') && str_ends_with($w, '/') && mb_strlen($w) >= 2) {
            $w = trim(mb_substr($w, 1, mb_strlen($w) - 2));
        }
        $w = trim(preg_split('/[,\/;]/u', $w, 2)[0] ?? '');

        return str_replace('-', '', trim($w, '-'));
    }
}
