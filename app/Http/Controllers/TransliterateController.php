<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * H1463 — Sanskrit-HUB L5 Workstream-A v0: /transliterate playground.
 *
 * Flag-gated client-side IAST→Devanāgarī+SLP1 demo using the vendored
 * sanskrit-util transcoder (resources/js/vendor/sanskrit-util.js). Mirrors
 * ReadingPackController's default-OFF gate pattern.
 *
 * Executed by Grok 4.5 (grok-4.5) via xAI under user-authorized Opus-lock
 * override — Claude/Opus should verify after merge.
 */
class TransliterateController extends Controller
{
    public function show(): View
    {
        abort_if(! config('features.hub_transliterate', false), 404);

        return view('hub.transliterate');
    }
}
