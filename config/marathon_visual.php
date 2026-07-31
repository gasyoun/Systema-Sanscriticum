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
];
