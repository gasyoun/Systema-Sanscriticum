<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BillingSubscriptionMode;

/**
 * Dark feature gate for Tochka multi-mode recurring (H2026 Phase 0).
 *
 * Master: config('features.tochka_recurring') ← TOCHKA_RECURRING_ENABLED (default false).
 * Modes:  config('features.tochka_recurring_modes') ← comma list TOCHKA_RECURRING_MODES.
 *
 * Phase 0 ships schema/models only; no live Tochka subscription calls while master is OFF.
 */
final class TochkaRecurring
{
    public static function enabled(): bool
    {
        return (bool) config('features.tochka_recurring', false);
    }

    /**
     * @return list<string>
     */
    public static function allowedModes(): array
    {
        $raw = config('features.tochka_recurring_modes', []);

        if (is_string($raw)) {
            $parts = array_map('trim', explode(',', $raw));
        } elseif (is_array($raw)) {
            $parts = $raw;
        } else {
            return [];
        }

        return array_values(array_filter(
            $parts,
            static fn ($m) => is_string($m) && $m !== ''
        ));
    }

    public static function modeAllowed(BillingSubscriptionMode|string $mode): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $value = $mode instanceof BillingSubscriptionMode ? $mode->value : $mode;

        return in_array($value, self::allowedModes(), true);
    }
}
