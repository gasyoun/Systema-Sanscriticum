<?php

/*
|--------------------------------------------------------------------------
| Konsultaciya landing — visual variant switch (H1975)
|--------------------------------------------------------------------------
| Orthogonal to config/marathon_landing_copy.php's MARATHON_LANDING_COPY_
| VARIANT — this axis is chrome/layout (a|b|c|d), not copy. Multi-direction
| policy (H1966 packet): several skins ship concurrently, B is the default,
| not a sole winner. See App\Support\MarathonVisual for the resolver.
*/

return [
    'variant' => env('MARATHON_LANDING_VISUAL_VARIANT', 'b'),

    /*
    |----------------------------------------------------------------------
    | H4521 — «Как проходит консультация» scrollytelling block
    |----------------------------------------------------------------------
    | Structural block on skin b only (shared partial marathon.skins._scrolly),
    | OFF by default: the copy A/B split runs until 2026-11-01 and the block
    | must not move the copy axis. QA override: ?scrolly=1 (never persisted).
    | Storyboard: marketing/marathon-2026-08/redesign/STORYBOARD_konsultaciya-
    | scrolly_10.09.26.md. Prod flip is a separate human step after the read.
    */
    'scrollytelling' => (bool) env('MARATHON_VISUAL_SCROLLYTELLING', false),
];
