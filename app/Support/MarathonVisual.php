<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * H1975 — resolved visual variant (chrome/layout skin) for the marathon
 * landing. Independent of MarathonLandingCopy's copy-variant axis. Default
 * B (light island); ?skin= is a QA-only override, never persisted.
 */
final class MarathonVisual
{
    public const VARIANTS = ['a', 'b', 'c', 'd'];

    public static function variantKey(?Request $request = null): string
    {
        $request ??= request();

        $skin = strtolower((string) $request->query('skin', ''));
        if (in_array($skin, self::VARIANTS, true)) {
            return $skin;
        }

        $default = strtolower((string) config('marathon_visual.variant', 'b'));

        return in_array($default, self::VARIANTS, true) ? $default : 'b';
    }
}
