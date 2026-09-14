<?php

namespace App\Http\Controllers;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Services\SanskritGlossary;
use App\Support\CdslLinks;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SEO P2 (H204) — public dictionary entity pages `/slovar`.
 *
 * Wave 0: every page is `noindex,follow` (see config/dictionary_seo.php + the
 * `isIndexable()` model gate) and word URLs are withheld from the sitemap. The
 * pages exist and interlink now; indexing is a monitored follow-on wave.
 */
class DictionaryPageController extends Controller
{
    /** Hub `/slovar` — dictionaries overview + a paginated word list (optional ?dict= filter). */
    public function index(Request $request): View
    {
        $dictionaries = Dictionary::where('is_active', true)
            ->withCount('words')
            ->orderBy('name')
            ->get();

        $activeDictionaryId = $request->integer('dict') ?: null;
        $activeDictionary = $activeDictionaryId
            ? $dictionaries->firstWhere('id', $activeDictionaryId)
            : null;

        $words = DictionaryWord::query()
            ->whereNotNull('slug')
            ->whereHas('dictionary', fn ($q) => $q->where('is_active', true))
            ->when($activeDictionary, fn ($q) => $q->where('dictionary_id', $activeDictionary->id))
            ->orderBy('iast')
            ->paginate(60)
            ->withQueryString();

        return view('slovar.index', [
            'dictionaries' => $dictionaries,
            'activeDictionary' => $activeDictionary,
            'words' => $words,
        ]);
    }

    /**
     * Canonical word page `/slovar/{slug}` — collapses every dictionary entry that
     * shares this headword slug onto one page (decision D3). The richest translation
     * is the primary sense; the rest render as per-dictionary sections.
     */
    public function show(string $slug, SanskritGlossary $glossary): View
    {
        $entries = DictionaryWord::query()
            ->where('slug', $slug)
            ->whereHas('dictionary', fn ($q) => $q->where('is_active', true))
            ->with('dictionary')
            ->get();

        abort_if($entries->isEmpty(), 404);

        // Canonical → the richest source (longest translation).
        $primary = $entries->sortByDesc(fn ($w) => mb_strlen((string) $w->translation))->first();

        // D4: emit `sameAs` only for mappings that actually exist (never guess).
        $sameAs = $entries
            ->pluck('wikidata_qid')
            ->filter()
            ->unique()
            ->map(fn ($qid) => rtrim(config('dictionary_seo.wikidata_base'), '/').'/'.$qid)
            ->values();

        // Related headwords from the same dictionaries (cheap internal linking; feeds P1-b).
        $related = DictionaryWord::query()
            ->whereIn('dictionary_id', $entries->pluck('dictionary_id')->unique())
            ->whereNotNull('slug')
            ->where('slug', '!=', $slug)
            ->inRandomOrder()
            ->limit(12)
            ->get(['slug', 'iast', 'devanagari', 'cyrillic'])
            ->unique('slug')
            ->take(8)
            ->values();

        // H344 — корпусная Sa→Ru глосса из вендорного фида (за фича-флагом, по умолч. OFF).
        // Enrich по каноническому (самому «богатому») слову; null, когда флаг выкл /
        // слово не засвидетельствовано в корпусе.
        $enrichment = $glossary->enrich($primary);

        // H3762 — Cologne CDSL link-out (server-side URLs; empty when no SLP1 key resolves).
        $cdslLinks = CdslLinks::forIast($primary->iast);

        return view('slovar.show', [
            'slug' => $slug,
            'entries' => $entries,
            'primary' => $primary,
            'sameAs' => $sameAs,
            'related' => $related,
            'indexable' => $entries->contains(fn ($w) => $w->isIndexable()),
            'enrichment' => $enrichment,
            'cdslLinks' => $cdslLinks,
        ]);
    }
}
