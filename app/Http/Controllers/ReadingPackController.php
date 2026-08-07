<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Services\Activity\ActivityTracker;
use App\Services\StartChteniyaProgress;
use App\Support\StartChteniyaCohort;
use App\Support\StartChteniyaSrsDeck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
 *
 * H2111 completes the tap-token panel: the disclosure already showed form/lemma/morph/
 * gloss/gloss_ru (H2110's shared partial), so what was left is the ADD-TO-SRS path —
 * `addToSrs()` below, POSTed from the panel, writing into the student's own private
 * cohort deck through App\Support\StartChteniyaSrsDeck (the same deck H2106's bulk
 * import fills; no second deck, no second dedupe rule).
 *
 * Two deliberate properties of that path:
 *
 *  - the client sends only POSITIONS (sentence index + token index), never a lemma or a
 *    gloss. The card's content is read out of the pinned freeze server-side, so a forged
 *    POST cannot inject arbitrary text into a student's deck;
 *  - it degrades instead of demanding a lemmatizer. A token that carries no `lemma` in
 *    the feed is refused with an honest message — H2111's own fail condition is
 *    "require lemmatizer always-on", so the always-on cascade (H1463) is not a
 *    prerequisite here.
 *
 * H2107 adds student progress + a teacher stalled-lemma view on top of the same two
 * pipelines — no third one. `logLookup()` writes a fire-and-forget `activity_events`
 * row (type `reading.token.lookup`, positions-only body, same forgery-proofing as
 * `addToSrs()`) each time a student opens a token's disclosure; `addToSrs()` already
 * writes the private cohort SRS deck. `App\Services\StartChteniyaProgress` reads both
 * to compute per-student lookup % / unique-lemma count and the teacher-facing
 * top-stalled-lemma ranking (lookups that never converted to a collected card).
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
        $user = $request->user();

        $packs = [];
        foreach (self::COHORT_PACKS as $slug) {
            $pack = $this->readPack($slug);
            // H2107 — lookup % is per-pack (lemmas that pack's tokens carry);
            // unique-lemma count is per-student across the whole cohort deck.
            $pack['lookup_percent'] = StartChteniyaProgress::lookupPercentFor($user, $pack);
            $packs[] = $pack;
        }

        return view('student.reading-index', [
            'packs' => $packs,
            'uniqueLemmas' => StartChteniyaProgress::uniqueLemmaCountFor($user),
        ]);
    }

    public function cabinetShow(Request $request, string $slug): View
    {
        $this->authorizeCohortReader($request);

        // Never resolve an arbitrary slug from the URL into a path: only packs this
        // controller offers are readable, so a traversal attempt is a 404, not a file read.
        abort_unless(in_array($slug, self::COHORT_PACKS, true), 404);

        $viewData = $this->packViewData($slug);
        $viewData['lookup_percent'] = StartChteniyaProgress::lookupPercentFor($request->user(), $viewData['pack']);
        $viewData['unique_lemmas'] = StartChteniyaProgress::uniqueLemmaCountFor($request->user());

        // srsAdd is what turns the tap panel's «в колоду» buttons on. Only here: the
        // public demo route renders the same partial and must stay a read-only page.
        return view('student.reading-pack', $viewData + ['srsAdd' => true]);
    }

    /**
     * H2107 — fire-and-forget: a student opened a token's disclosure panel.
     *
     * Same security property as addToSrs(): the client sends only POSITIONS, the
     * lemma is read server-side from the pinned freeze, so a forged POST cannot
     * inject arbitrary text into activity_events. Always 204 — telemetry must
     * never surface an error to the reader.
     */
    public function logLookup(Request $request, string $slug): Response
    {
        $this->authorizeCohortReader($request);
        abort_unless(in_array($slug, self::COHORT_PACKS, true), 404);

        $validated = $request->validate([
            'sentence' => ['required', 'integer', 'min:0'],
            'token' => ['required', 'integer', 'min:0'],
        ]);

        $pack = $this->readPack($slug);
        $sentence = $pack['sentences'][$validated['sentence']] ?? null;
        $token = $sentence['tokens'][$validated['token']] ?? null;
        $lemma = trim((string) ($token['lemma'] ?? ''));

        if ($token !== null && $lemma !== '') {
            app(ActivityTracker::class)->logEvent(
                user: $request->user(),
                session: null,
                type: ActivityEvent::TYPE_READING_TOKEN_LOOKUP,
                data: [
                    'pack' => (string) ($pack['slug'] ?? $slug),
                    'lemma_slp1' => trim((string) ($token['slp1'] ?? '')) ?: $lemma,
                    'surface' => (string) ($token['form'] ?? ''),
                ],
                request: $request,
            );
        }

        return response()->noContent();
    }

    /**
     * H2111 — add one tapped token's lemma to the student's private cohort SRS deck.
     *
     * Gated exactly like the reader itself (flag + entitlement) and NOT by
     * config('srs.enabled'): H2106's fence forbids touching the global SRS switch, and a
     * card collected while the review UI is off is simply waiting for it to be turned on.
     */
    public function addToSrs(Request $request, string $slug): RedirectResponse
    {
        $this->authorizeCohortReader($request);
        abort_unless(in_array($slug, self::COHORT_PACKS, true), 404);

        $validated = $request->validate([
            'sentence' => ['required', 'integer', 'min:0'],
            'token' => ['required', 'integer', 'min:0'],
        ]);

        $pack = $this->readPack($slug);
        $sentence = $pack['sentences'][$validated['sentence']] ?? null;
        abort_if($sentence === null, 404);
        $token = $sentence['tokens'][$validated['token']] ?? null;
        abort_if($token === null, 404);

        $lemma = trim((string) ($token['lemma'] ?? ''));
        if ($lemma === '') {
            return back()->with('reading_srs_status', 'no_lemma')
                ->with('reading_srs_word', (string) ($token['form'] ?? ''));
        }

        $glossRu = $token['gloss_ru']['surface'] ?? ($token['gloss_ru']['lemma'] ?? '');

        $fields = [
            'pack' => (string) ($pack['slug'] ?? $slug),
            // slp1 is the LEMMA in SLP1 in this feed (siddhi -> sidDi), which is what the
            // H2106 TSV's lemma_slp1 column holds — so both writers key on the same value.
            'lemma_slp1' => trim((string) ($token['slp1'] ?? '')) ?: $lemma,
            'surface' => (string) ($token['form'] ?? ''),
            'gloss_ru' => (string) $glossRu,
            'gloss_en' => (string) ($token['gloss'] ?? ''),
            'locus' => (string) ($sentence['n'] ?? ''),
        ];

        $deck = StartChteniyaSrsDeck::deckFor($request->user());

        if (isset(StartChteniyaSrsDeck::existingKeys($deck)[StartChteniyaSrsDeck::cardKey($fields)])) {
            return back()->with('reading_srs_status', 'duplicate')
                ->with('reading_srs_word', $lemma);
        }

        StartChteniyaSrsDeck::createCard($deck, $fields);

        return back()->with('reading_srs_status', 'added')
            ->with('reading_srs_word', $lemma);
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
