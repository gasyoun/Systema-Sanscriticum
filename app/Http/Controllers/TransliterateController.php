<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * H1463 — Sanskrit-HUB L5 Workstream-A v0: /transliterate playground.
 * H2763 — public /sanskritorium wrapper over the same view + JS.
 *
 * Client-side IAST→Devanāgarī+SLP1 via vendored sanskrit-util
 * (resources/js/vendor/sanskrit-util.js). Do not fork that engine.
 * /transliterate stays behind features.hub_transliterate (default OFF).
 * /sanskritorium is the public indexable path and ignores the flag.
 */
class TransliterateController extends Controller
{
    public function show(): View
    {
        abort_if(! config('features.hub_transliterate', false), 404);

        return view('hub.transliterate', [
            'indexable' => false,
        ]);
    }

    public function sanskritorium(): View
    {
        return view('hub.transliterate', [
            'indexable' => true,
        ]);
    }
}
