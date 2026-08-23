<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Per-credential login throttle (H3314)
    |--------------------------------------------------------------------------
    |
    | Laravel-standard credential lockout shared by web /shop and API login,
    | mirroring AuthenticatesUsers semantics without resurrecting the trait.
    | Two counters are kept per attempt — `email|ip` (standard) and a
    | account-wide `email` counter so IP-rotating brute force against one
    | account is still locked out. Counters clear on successful login.
    |
    */

    'enabled' => (bool) env('LOGIN_THROTTLE_ENABLED', true),

    'max_attempts' => (int) env('LOGIN_THROTTLE_MAX_ATTEMPTS', 5),

    'decay_seconds' => (int) env('LOGIN_THROTTLE_DECAY_SECONDS', 60),

];
