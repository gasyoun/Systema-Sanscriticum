<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | Video hosts / pilots (H1451 — Anton ops-gaps W3)
 |--------------------------------------------------------------------------
 |
 | kinescope_pilot_course_id: single Course.id that may render Kinescope as a
 | first-class lesson host when features.kinescope_pilot is ON. Null/empty =
 | no course is in the pilot (even if the flag is flipped).
 */

return [
    'kinescope_pilot_course_id' => env('KINESCOPE_PILOT_COURSE_ID'),
];
