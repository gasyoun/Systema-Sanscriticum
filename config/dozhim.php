<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Noboring «дожим» operator queue (H2119 / Wave 1b)
|--------------------------------------------------------------------------
| Thresholds for the unpaid/open-Deal work bucket. Flag dozhim_queue gates
| whether the bucket is queried/rendered (default OFF — deploy-safe).
*/

return [

    // Open Deal older than this many hours enters the dozhim queue.
    // Default 24h; NF case used ~week for cold leads — override via env.
    'unpaid_deal_hours' => (int) env('DOZHIM_UNPAID_DEAL_HOURS', 24),

];
