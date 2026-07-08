<?php

namespace App\Services\Seo;

use App\Models\DictionaryWord;
use Illuminate\Support\Facades\Http;

/**
 * SEO P2 Wave-1 (H210, Track B / decision D4) — automated Wikidata `sameAs` matcher.
 *
 * Populates `dictionary_words.wikidata_qid` so the `DefinedTerm` `sameAs` triplet can
 * emit. Precision-first by design: D4 says NEVER emit an unverified `sameAs`, so the
 * only self-writing signal is an EXACT Sanskrit-script (`sa`) label/alias equality with
 * the headword Devanāgarī. A weaker IAST↔English-label match is surfaced (for a human
 * spot-check) but scores below the default write threshold, so by default it does not
 * write. Recall is deliberately traded for precision.
 *
 * Prior art: no Sanskrit↔Wikidata/DBpedia crosswalk exists in any sibling repo
 * (csl-atlas / kosha / PROJECT_INTERLINKS, confirmed 05-07-2026) — hence built here.
 */
class WikidataSameAsMatcher
{
    private const ENDPOINT = 'https://www.wikidata.org/w/api.php';

    /** Descriptive UA — Wikidata blocks/throttles generic agents. */
    private const USER_AGENT = 'Systema-Sanscriticum/1.0 (https://samskrte.ru; dictionary sameAs matcher) Laravel-Http';

    /** Exact Sanskrit-script label/alias match — safe to auto-write. */
    public const SCORE_DEVANAGARI_EXACT = 1.0;

    /** IAST equals a Wikidata English label — plausible but collision-prone; review, don't auto-write. */
    public const SCORE_IAST_ENGLISH = 0.75;

    /**
     * P31 "instance of" targets that a Sanskrit *concept* headword must never map to.
     * A Devanāgarī label/alias collides freely with bands, rivers, people and given
     * names (spot-check 08-07-2026: निर्वाण→Nirvana the band, गज→a given name), so an
     * exact-script hit whose entity is one of these is rejected rather than written.
     */
    private const INSTANCE_OF_DENYLIST = [
        'Q5',         // human
        'Q215380',    // musical group
        'Q2088357',   // musical ensemble
        'Q105756498', // human music group
        'Q4022',      // river
        'Q11424',     // film
        'Q482994',    // album
        'Q134556',    // single (music)
        'Q101352',    // family name
        'Q202444',    // given name
        'Q12308941',  // male given name
        'Q11879590',  // female given name
        'Q3409032',   // unisex given name
        'Q486972',    // human settlement
        'Q532',       // village
        'Q3957',      // town
        'Q515',       // city
        'Q4167410',   // Wikimedia disambiguation page
        'Q13406463',  // Wikimedia list article
    ];

    public function __construct(
        private readonly int $searchLimit = 7,
    ) {}

    /**
     * Best Wikidata match for a headword, or null when nothing clears a real signal.
     *
     * @return array{qid:string,score:float,label:?string,description:?string,signal:string}|null
     */
    public function match(DictionaryWord $word): ?array
    {
        $devanagari = trim((string) $word->devanagari);
        $iast = trim((string) $word->iast);

        $best = null;

        // Signal 1 (auto-write): exact Devanāgarī label/alias in the Sanskrit language,
        // whose entity survives the P31 "instance of" sanity filter (rejects the band /
        // river / person / given-name / disambiguation collisions the spot-check exposed).
        if ($devanagari !== '') {
            foreach ($this->search($devanagari, 'sa') as $hit) {
                if (! $this->matchesExactScript($hit, $devanagari)) {
                    continue;
                }
                $qid = (string) ($hit['id'] ?? '');
                if ($qid !== '' && $this->isDeniedInstance($qid)) {
                    continue; // e.g. निर्वाण → Nirvana (band) — skip, try the next exact hit
                }
                $best = $this->candidate($hit, self::SCORE_DEVANAGARI_EXACT, 'devanagari-sa-exact');
                break; // top surviving exact match wins
            }
        }

        // Signal 2 (review-only): IAST equals a Wikidata English label. Never overrides
        // a stronger signal-1 hit; only fills in when signal 1 found nothing.
        if ($best === null && $iast !== '') {
            $iastSlug = DictionaryWord::makeHeadwordSlug($iast);
            foreach ($this->search($iast, 'en') as $hit) {
                $label = (string) ($hit['label'] ?? '');
                if ($iastSlug !== '' && DictionaryWord::makeHeadwordSlug($label) === $iastSlug) {
                    $best = $this->candidate($hit, self::SCORE_IAST_ENGLISH, 'iast-en-label');
                    break;
                }
            }
        }

        return $best;
    }

    /**
     * wbsearchentities call. Returns the raw search hits (each may carry a `match`
     * object describing which label/alias/language matched the query).
     *
     * @return array<int, array<string, mixed>>
     */
    private function search(string $term, string $language): array
    {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(20)
            ->retry(2, 500, throw: false)
            ->get(self::ENDPOINT, [
                'action' => 'wbsearchentities',
                'search' => $term,
                'language' => $language,
                'uselang' => $language,
                'type' => 'item',
                'format' => 'json',
                'limit' => $this->searchLimit,
            ]);

        if (! $response->successful()) {
            return [];
        }

        return $response->json('search', []);
    }

    /**
     * True when the hit matched via an exact Sanskrit-script label or alias equal to
     * the query (ignoring surrounding whitespace). The `match` block tells us WHAT
     * matched — we require language `sa` and text equality, not a mere substring.
     */
    private function matchesExactScript(array $hit, string $query): bool
    {
        $match = $hit['match'] ?? [];
        $type = $match['type'] ?? null;
        $language = $match['language'] ?? null;
        $text = trim((string) ($match['text'] ?? ''));

        if (! in_array($type, ['label', 'alias'], true) || $language !== 'sa') {
            return false;
        }

        return $this->normalizeDeva($text) === $this->normalizeDeva($query);
    }

    private function normalizeDeva(string $s): string
    {
        // Collapse whitespace only; keep the script intact so equality stays exact.
        return trim(preg_replace('/\s+/u', '', $s) ?? '');
    }

    /**
     * True when the entity's P31 ("instance of") intersects the denylist — i.e. it is a
     * band / river / person / given name / place / disambiguation page, not a Sanskrit
     * concept. On any API hiccup we return false (fail-open on the FILTER means the
     * exact-script signal still stands, which the human spot-check then reviews).
     */
    private function isDeniedInstance(string $qid): bool
    {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(20)
            ->retry(2, 500, throw: false)
            ->get(self::ENDPOINT, [
                'action' => 'wbgetentities',
                'ids' => $qid,
                'props' => 'claims',
                'format' => 'json',
            ]);

        if (! $response->successful()) {
            return false;
        }

        $claims = $response->json("entities.{$qid}.claims.P31", []);

        foreach ($claims as $claim) {
            $instanceOf = data_get($claim, 'mainsnak.datavalue.value.id');
            if ($instanceOf !== null && in_array($instanceOf, self::INSTANCE_OF_DENYLIST, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array{qid:string,score:float,label:?string,description:?string,signal:string}
     */
    private function candidate(array $hit, float $score, string $signal): array
    {
        return [
            'qid' => (string) ($hit['id'] ?? ''),
            'score' => $score,
            'label' => $hit['label'] ?? null,
            'description' => $hit['description'] ?? null,
            'signal' => $signal,
        ];
    }
}
