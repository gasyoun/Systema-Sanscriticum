<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * H3314 — per-credential login throttle shared by web and API login.
 *
 * Two counters per attempt, both with the same threshold/decay:
 *  - `login:{sha1(email|ip)}` — Laravel-standard AuthenticatesUsers semantics;
 *  - `login-account:{sha1(email)}` — account-wide counter so a distributed /
 *    IP-rotating brute force against ONE account is still locked out
 *    (acceptance: "locked after threshold regardless of source IP variation").
 *
 * Counters are hit on ANY failed attempt, existing account or not, and the
 * lockout message is uniform — no email-existence oracle.
 */
class LoginThrottle
{
    /** Единое сообщение блокировки для web, shop и API — без оракула существования email. */
    public const LOCKOUT_MESSAGE = 'Слишком много попыток входа. Попробуйте снова позже.';

    /**
     * Bcrypt digest of a throwaway string (not a real credential): burned on
     * the unknown-account path so response timing matches a wrong-password hit.
     */
    private const DUMMY_HASH = '$2y$10$5n6ecIsm13y04GWnvtX7euXfXxtPPS2r3Yk/OBZppwiMxNlYjwQHm';

    public static function enabled(): bool
    {
        return (bool) config('login_throttle.enabled', true);
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('login_throttle.max_attempts', 5));
    }

    public static function decaySeconds(): int
    {
        return max(1, (int) config('login_throttle.decay_seconds', 60));
    }

    public static function tooManyAttempts(string $email, string $ip): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return RateLimiter::tooManyAttempts(self::credentialKey($email), self::maxAttempts())
            || RateLimiter::tooManyAttempts(self::ipKey($email, $ip), self::maxAttempts());
    }

    public static function hit(string $email, string $ip): void
    {
        if (! self::enabled()) {
            return;
        }

        RateLimiter::hit(self::ipKey($email, $ip), self::decaySeconds());
        RateLimiter::hit(self::credentialKey($email), self::decaySeconds());
    }

    public static function clear(string $email, ?string $ip = null): void
    {
        RateLimiter::clear(self::credentialKey($email));

        if ($ip !== null) {
            RateLimiter::clear(self::ipKey($email, $ip));
        }
    }

    public static function fireLockout(Request $request): void
    {
        event(new Lockout($request));
    }

    public static function equalizeTiming(string $plainPassword): void
    {
        Hash::check($plainPassword, self::DUMMY_HASH);
    }

    public static function credentialKey(string $email): string
    {
        return 'login-account:'.sha1(mb_strtolower(trim($email)));
    }

    public static function ipKey(string $email, string $ip): string
    {
        return 'login:'.sha1(mb_strtolower(trim($email)).'|'.$ip);
    }
}
