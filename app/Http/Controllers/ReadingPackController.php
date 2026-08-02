<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\StartChteniyaCohort;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * H959/H965 — Rungs of the kosha->Systema last-mile pipeline reader-as-a-service.
 *
 * Renders a VENDORED static reading pack — resources/data/kosha_reading_pack_nala_1.json,
 * derived from kosha's dcs-reading-pack-nala-1 (our own derived data, no live dependency
 * on kosha or a sibling-repo path). Every token carries lemma/morph/gloss inline in the
 * feed, so word-tap disclosure needs no external link or runtime lookup — see
 * SanskritGrammar docs/LAST_MILE_PIPELINE_SPEC.md §1's vendored-file ruling.
 *
 * H965 (Hop C) adds the difficulty-score consumption: a second vendored feed,
 * resources/data/kosha_reading_pack_difficulty.json (kosha's real `reading-pack-difficulty`
 * dataset from its H949 scorer — NOT re-derived here), read as ADVISORY metadata only
 * (never used to auto-reorder anything) per the spec's Hop C ruling.
 *
 * Gated by config('features.kosha_reader'); OFF by default — the route 404s exactly like
 * /slovar did before its own Wave 0 (H204).
 *
 * H2110 (Wave 2) makes the reader MULTI-PACK and puts it inside the cabinet:
 *
 *  - the pack is now addressed by SLUG rather than baked into a const, and resolved from
 *    the H2109 «Старт чтения» freeze vendored under resources/data/cohort_start_chteniya/
 *    (sha256-pinned against the freeze's own MANIFEST by
 *    scripts/vendor_cohort_start_chteniya_packs.py) with the pre-existing nala-1 demo feed
 *    kept as a legacy alias so /reading/kosha-demo is untouched;
 *  - the in-cabinet routes are gated by StartChteniyaCohort::hasEntitlement() — a real paid
 *    cohort student — ON TOP OF the cohort feature flag, so a logged-in non-buyer 404s;
 *  - NO new schema. The freeze also pins `subhashita-beginner`, whose shape is
 *    sayings[]/lines[].chunks[] rather than sentences[]/tokens[]; adapting it is deliberately
 *    NOT done here (this handoff's own stated failure mode is "second pack schema"), and it
 *    is therefore not vendored at all rather than half-imported.
 */
class ReadingPackController extends Controller
{
    private const FEED_PATH = 'data/kosha_reading_pack_nala_1.json';

    private const DIFFICULTY_FEED_PATH = 'data/kosha_reading_pack_difficulty.json';

    /** Where the H2109 freeze copies live, relative to resource_path(). */
    private const COHORT_FEED_DIR = 'data/cohort_start_chteniya';

    /**
     * Slugs whose feed does NOT live in the cohort freeze directory.
     *
     * `nala-1` predates the freeze and keeps its historical flat path so the public demo
     * route's behaviour (and its six H959/H965 tests) is bit-for-bit unchanged.
     */
    private const LEGACY_FEEDS = ['nala-1' => self::FEED_PATH];

    /** Packs offered in the cabinet, in course order. Slugs only — titles come from the feed. */
    private const COHORT_PACKS = ['hitopadesa-0'];

    public function show(): View
    {
        abort_if(! config('features.kosha_reader', false), 404);

        return view('reading.kosha-demo', $this->packViewData('nala-1'));
    }

    /**
     * The cohort reading list inside the cabinet.
     *
     * Entitlement, not merely authentication: `hasEntitlement()` is false unless the cohort
     * flag is on AND the user has a real non-deposit/non-trial paid payment for the course.
     */
    public function cabinetIndex(Request $request): View
    {
        $this->authorizeCohortReader($request);

        $packs = [];
        foreach (self::COHORT_PACKS as $slug) {
            $packs[] = $this->readPack($slug);
        }

        return view('student.reading-index', ['packs' => $packs]);
    }

    public function cabinetShow(Request $request, string $slug): View
    {
        $this->authorizeCohortReader($request);

        // Never resolve an arbitrary slug from the URL into a path: only packs this
        // controller offers are readable, so a traversal attempt is a 404, not a file read.
        abort_unless(in_array($slug, self::COHORT_PACKS, true), 404);

        return view('student.reading-pack', $this->packViewData($slug));
    }

    /**
     * Fail closed on both gates, in the repo's prevailing inline-abort idiom (no new
     * middleware): the reader flag, then the cohort entitlement itself.
     */
    private function authorizeCohortReader(Request $request): void
    {
        abort_if(! config('features.kosha_reader', false), 404);
        abort_unless(StartChteniyaCohort::hasEntitlement($request->user()), 404);
    }

    /** @return array{pack:array,difficulty:?array,rankedPacks:list<array>} */
    private function packViewData(string $slug): array
    {
        $pack = $this->readPack($slug);
        $difficulty = $this->readDifficulty($pack['slug'] ?? null);

        return [
            'pack' => $pack,
            'difficulty' => $difficulty['own'],
            'rankedPacks' => $difficulty['ranked'],
        ];
    }

    /**
     * @return array{slug:string,title:string,ref:string,text_name:string,source:string,stats:array,sentences:list<array>}
     */
    private function readPack(string $slug): array
    {
        $relativePath = self::LEGACY_FEEDS[$slug] ?? self::COHORT_FEED_DIR.'/'.$slug.'.json';
        $data = $this->readJson($relativePath);

        if (! isset($data['sentences'])) {
            throw new RuntimeException('Invalid or malformed reading pack at '.$relativePath);
        }

        return $data;
    }

    /**
     * Advisory difficulty metadata for the current pack + the full ranked list
     * (easiest -> hardest) from kosha's H949 scorer. Returns nulls when the
     * current pack wasn't scored (e.g. no UD morphology) rather than fabricating
     * a number — mirrors the scorer's own fail-closed convention.
     *
     * @return array{own:?array,ranked:list<array>}
     */
    private function readDifficulty(?string $slug): array
    {
        $data = $this->readJson(self::DIFFICULTY_FEED_PATH);
        $packs = $data['packs'] ?? [];

        $own = null;
        foreach ($packs as $row) {
            if (($row['slug'] ?? null) === $slug) {
                $own = $row;
                break;
            }
        }

        return ['own' => $own, 'ranked' => $packs];
    }

    /** @return array<string,mixed> */
    private function readJson(string $relativePath): array
    {
        $path = resource_path($relativePath);

        if (! is_file($path)) {
            throw new RuntimeException("Feed not found at {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException("Invalid or malformed feed at {$path}");
        }

        return $data;
    }
}
