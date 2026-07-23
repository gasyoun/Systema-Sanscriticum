<?php

declare(strict_types=1);

namespace App\Services\Nlp;

use App\Services\SanskritGlossary;
use Illuminate\Support\Facades\Cache;

/**
 * H1463 — Sanskrit-HUB L5 workstream A, v0: the cascade lemmatizer.
 *
 * Resolves a Sanskrit surface form to its lemma by trying ordered resolver
 * stages and returning the FIRST non-null hit, recording which stage answered:
 *
 *   1. dcs      — real: the vendored DCS form2lemma slice (E27), our own derived
 *                 static feed (resources/data/dcs_form2lemma.json), no live
 *                 dependency on kosha or a sibling-repo path. Same vendored-feed
 *                 discipline as App\Services\SanskritGlossary / ReadingPackController.
 *   2. vidyut   — stubbed in v0 (E29). vidyut is a Rust/Python engine, not a static
 *                 table; live wiring is Q4 /nlp productionisation, out of scope for a
 *                 watcher-afflicted, FTP-local, flag-off v0.
 *   3. heritage — stubbed in v0 (D19). Heritage is likewise an engine (M11), not a
 *                 static table; same deferral as vidyut.
 *
 * Keys are normalised with SanskritGlossary::normalizeKey() (NFC + trim + lower on
 * the IAST form) — the SAME normaliser the feed was built with — so a lookup can
 * never miss on a key-convention mismatch. Internal only: no HTTP route, not gated
 * by any feature flag (the /transliterate flag gates the playground, not this).
 */
class CascadeLemmatizer
{
    private const DCS_FEED_PATH = 'data/dcs_form2lemma.json';

    /** Ordered resolver stages; first non-null hit wins. */
    private const STAGES = ['dcs', 'vidyut', 'heritage'];

    /** In-request memo of the decoded DCS feed (['key' => entry, ...]). */
    private static ?array $dcsTable = null;

    public function __construct(private readonly ?string $dcsPath = null) {}

    /**
     * Resolve a surface form to its lemma + the stage that answered, or null when
     * no stage resolves it (unknown / gibberish / empty).
     *
     * @return array{lemma: string, stage: string, pos: ?string, confidence: float}|null
     */
    public function lemmatize(string $form): ?array
    {
        $key = SanskritGlossary::normalizeKey($form);
        if ($key === '') {
            return null;
        }

        foreach (self::STAGES as $stage) {
            $hit = match ($stage) {
                'dcs' => $this->resolveDcs($key),
                'vidyut' => $this->resolveVidyut($key),
                'heritage' => $this->resolveHeritage($key),
                default => null,
            };

            if ($hit !== null) {
                return [
                    'lemma' => $hit['lemma'],
                    'stage' => $stage,
                    'pos' => $hit['pos'] ?? null,
                    'confidence' => $hit['confidence'] ?? 1.0,
                ];
            }
        }

        return null;
    }

    /** Stage 1 (real): exact lookup in the vendored DCS form2lemma slice. */
    private function resolveDcs(string $key): ?array
    {
        $entry = $this->dcsTable()[$key] ?? null;
        if (! is_array($entry) || empty($entry['lemma']) || ! is_string($entry['lemma'])) {
            return null;
        }

        return [
            'lemma' => $entry['lemma'],
            'pos' => (isset($entry['pos']) && is_string($entry['pos'])) ? $entry['pos'] : null,
            // Attested exact form→lemma match; DCS is the gold surface-form oracle here.
            'confidence' => 1.0,
        ];
    }

    /**
     * Stage 2 (stub): vidyut form2lemma (E29). v0 ships the resolver interface only;
     * a live vidyut call is Q4 /nlp work. Returns no-match so the cascade falls through.
     */
    private function resolveVidyut(string $key): ?array
    {
        return null;
    }

    /**
     * Stage 3 (stub): Heritage forms oracle (D19). Same deferral as vidyut — the
     * resolver interface is real, the engine wiring is a later slice.
     */
    private function resolveHeritage(string $key): ?array
    {
        return null;
    }

    /** Lazy-load + decode the DCS feed once per request (mtime-keyed app cache). */
    private function dcsTable(): array
    {
        if (self::$dcsTable !== null) {
            return self::$dcsTable;
        }

        $path = $this->dcsPath ?? resource_path(self::DCS_FEED_PATH);
        if (! is_file($path)) {
            return self::$dcsTable = [];
        }

        // Cache the decoded map keyed by file mtime so a re-vendored feed busts it.
        $entries = Cache::rememberForever('dcs_form2lemma:'.md5($path).':'.@filemtime($path), function () use ($path) {
            $decoded = json_decode((string) @file_get_contents($path), true);

            return is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])
                ? $decoded['entries']
                : [];
        });

        return self::$dcsTable = $entries;
    }

    /** Test seam: forget the in-request memo. */
    public static function flush(): void
    {
        self::$dcsTable = null;
    }
}
