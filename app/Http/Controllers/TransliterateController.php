<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * H1463 — Sanskrit-HUB L5 workstream A, v0: the /transliterate playground.
 *
 * Renders a client-side IAST→Devanāgarī+SLP1 converter driven by the VENDORED
 * canonical transcoder (resources/js/vendor/sanskrit-util.js, a verbatim copy of
 * sanskrit-lexicon/sanskrit-util — never a hand-rolled table). No server-side
 * transliteration: the page ships the vendored module and computes live in the
 * browser (mirrors csl-guides' Transliterator, ported to vanilla JS/Blade).
 *
 * Gated by config('features.hub_transliterate'); OFF by default — the route 404s
 * exactly like /reading/kosha-demo does before its own flag flip. The cascade
 * lemmatizer (App\Services\Nlp\CascadeLemmatizer) is internal-only and has no route.
 */
class TransliterateController extends Controller
{
    public function show(): View
    {
        abort_if(! config('features.hub_transliterate', false), 404);

        return view('hub.transliterate');
    }
}
