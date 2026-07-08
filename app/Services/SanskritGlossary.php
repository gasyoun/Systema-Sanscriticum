<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DictionaryWord;
use Illuminate\Support\Facades\Cache;

/**
 * Corpus Sa→Ru enrichment lookup for the public /slovar entity pages (H344).
 *
 * Reads a VENDORED static feed — resources/data/sa_ru_glossary.json — derived
 * from SanskritRussian/lemma_glossary.jsonl (DCS-attested; our own derived data,
 * no live dependency on kosha or sibling-repo paths). The feed is keyed by a
 * normalised IAST key (NFC, lower-case, trimmed), so a DictionaryWord matches by
 * its `iast` column with no runtime transcoder.
 *
 * Gated by config('features.slovar_enrichment'); when the flag is OFF nothing here
 * is called and /slovar renders exactly as in Wave 0 (H204).
 */
class SanskritGlossary
{
    /** In-request memo of the decoded feed (['key' => entry, ...]). */
    private static ?array $table = null;

    /** UPOS → короткая русская грамматическая помета (для отображения). */
    private const POS_RU = [
        'NOUN' => 'сущ.',
        'PROPN' => 'имя собств.',
        'VERB' => 'глаг.',
        'ADJ' => 'прил.',
        'ADV' => 'нареч.',
        'PRON' => 'мест.',
        'NUM' => 'числ.',
        'ADP' => 'предлог/послелог',
        'CCONJ' => 'союз',
        'SCONJ' => 'союз (подч.)',
        'PART' => 'частица',
        'INTJ' => 'междометие',
        'DET' => 'определитель',
        'AUX' => 'вспом.',
    ];

    public function __construct(private readonly ?string $path = null) {}

    /** Absolute path to the vendored feed (overridable in tests). */
    private function feedPath(): string
    {
        return $this->path ?? resource_path('data/sa_ru_glossary.json');
    }

    /**
     * Enrichment for a word, or null when the flag is off, the feed is missing,
     * or the headword is not attested in the corpus.
     *
     * @return array{glosses: string[], pos_raw: ?string, pos_label: ?string, freq: int, slp1: ?string}|null
     */
    public function enrich(DictionaryWord $word): ?array
    {
        if (! config('features.slovar_enrichment', false)) {
            return null;
        }

        $key = self::normalizeKey((string) $word->iast);
        if ($key === '') {
            return null;
        }

        $entry = $this->table()[$key] ?? null;
        if (! is_array($entry) || empty($entry['g'])) {
            return null;
        }

        $posRaw = $entry['pos'] ?? null;

        return [
            'glosses' => array_values(array_filter((array) $entry['g'], 'is_string')),
            'pos_raw' => $posRaw,
            'pos_label' => $posRaw ? (self::POS_RU[$posRaw] ?? null) : null,
            'freq' => (int) ($entry['n'] ?? 0),
            'slp1' => $entry['slp1'] ?? null,
        ];
    }

    /** Lazy-load + decode the feed once per request (with a short app-cache layer). */
    private function table(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $path = $this->feedPath();
        if (! is_file($path)) {
            return self::$table = [];
        }

        // Cache the decoded map keyed by file mtime so a re-vendored feed busts it.
        $entries = Cache::rememberForever('slovar_glossary:'.md5($path).':'.@filemtime($path), function () use ($path) {
            $decoded = json_decode((string) @file_get_contents($path), true);

            return is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])
                ? $decoded['entries']
                : [];
        });

        return self::$table = $entries;
    }

    /** Must mirror build_sa_ru_glossary.py norm_key(): NFC, lower-case, trim. */
    public static function normalizeKey(string $iast): string
    {
        $iast = trim($iast);
        if ($iast === '') {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $iast = \Normalizer::normalize($iast, \Normalizer::FORM_C) ?: $iast;
        }

        return mb_strtolower($iast, 'UTF-8');
    }

    /** Test seam: forget the in-request memo. */
    public static function flush(): void
    {
        self::$table = null;
    }
}
