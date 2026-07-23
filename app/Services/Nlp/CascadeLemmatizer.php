<?php

declare(strict_types=1);

namespace App\Services\Nlp;

use App\Services\SanskritGlossary;
use Illuminate\Support\Facades\Cache;

/**
 * H1463 — Sanskrit-HUB cascade lemmatizer v0 (Workstream A, Q3-2026 KPI).
 *
 * Ordered stages: DCS form2lemma → vidyut → Heritage. First non-null hit wins.
 * Stage 1 reads the vendored static feed resources/data/dcs_form2lemma.json
 * (mtime-cached, same shape as SanskritGlossary). Stages 2/3 are interface-
 * stubbed for v0 (no live engines; no-match until slices are vendored).
 *
 * Internal only — no HTTP route. Prod-inert until callers are wired.
 *
 * Executed by Grok 4.5 (grok-4.5) via xAI under user-authorized Opus-lock
 * override — Claude/Opus should verify cascade order + key normalizer parity.
 */
class CascadeLemmatizer
{
    private static ?array $dcsTable = null;

    public function __construct(
        private readonly ?string $dcsPath = null,
        private readonly ?string $vidyutPath = null,
        private readonly ?string $heritagePath = null,
    ) {}

    /**
     * @return array{lemma: string, stage: string, confidence: float, pos: ?string}|null
     */
    public function lemmatize(string $form): ?array
    {
        $key = SanskritGlossary::normalizeKey($form);
        if ($key === '') {
            return null;
        }

        foreach ($this->stages() as $stage => $resolver) {
            $hit = $resolver($key);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * Ordered stage name → resolver. First hit wins.
     *
     * @return array<string, callable(string): (?array{lemma: string, stage: string, confidence: float, pos: ?string})>
     */
    private function stages(): array
    {
        return [
            'dcs' => fn (string $key): ?array => $this->resolveFromTable($this->dcsTable(), $key, 'dcs', 0.9),
            'vidyut' => fn (string $key): ?array => $this->resolveFromTable($this->optionalTable($this->vidyutPath), $key, 'vidyut', 0.7),
            'heritage' => fn (string $key): ?array => $this->resolveFromTable($this->optionalTable($this->heritagePath), $key, 'heritage', 0.6),
        ];
    }

    /**
     * @param  array<string, array{lemma?: string, pos?: string}>  $table
     * @return array{lemma: string, stage: string, confidence: float, pos: ?string}|null
     */
    private function resolveFromTable(array $table, string $key, string $stage, float $confidence): ?array
    {
        $entry = $table[$key] ?? null;
        if (! is_array($entry) || empty($entry['lemma']) || ! is_string($entry['lemma'])) {
            return null;
        }

        $pos = $entry['pos'] ?? null;

        return [
            'lemma' => $entry['lemma'],
            'stage' => $stage,
            'confidence' => $confidence,
            'pos' => is_string($pos) && $pos !== '' ? $pos : null,
        ];
    }

    /** @return array<string, array{lemma?: string, pos?: string}> */
    private function dcsTable(): array
    {
        if (self::$dcsTable !== null) {
            return self::$dcsTable;
        }

        $path = $this->dcsPath ?? resource_path('data/dcs_form2lemma.json');
        if (! is_file($path)) {
            return self::$dcsTable = [];
        }

        $entries = Cache::rememberForever(
            'cascade_lemmatizer_dcs:'.md5($path).':'.@filemtime($path),
            function () use ($path): array {
                $decoded = json_decode((string) @file_get_contents($path), true);

                return is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])
                    ? $decoded['entries']
                    : [];
            }
        );

        return self::$dcsTable = $entries;
    }

    /**
     * Stages 2/3: only load if a path was injected (tests) or a future vendored
     * file appears. Default paths are null → empty table → no-match.
     *
     * @return array<string, array{lemma?: string, pos?: string}>
     */
    private function optionalTable(?string $path): array
    {
        if ($path === null || $path === '' || ! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['entries']) || ! is_array($decoded['entries'])) {
            return [];
        }

        return $decoded['entries'];
    }

    /** Test seam: forget the in-request DCS memo. */
    public static function flush(): void
    {
        self::$dcsTable = null;
    }
}
