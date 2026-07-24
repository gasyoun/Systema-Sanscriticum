<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | n8n lecture content engine (Wave 1+)
    |--------------------------------------------------------------------------
    |
    | SpanRanker top-N: сколько лучших спанов из ClipSpanPlanner реально идёт
    | в n8n на нарезку (было — весь пак до 12). Curator "expand" может
    | передать N явно, это лишь дефолт диспатча.
    */
    'clip_rank_n' => (int) env('CONTENT_CLIP_RANK_N', 5),
];
