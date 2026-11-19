<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H6888 — per-group gate for Learn Your Way (LYW) lesson packs.
 *
 * The pack tree already speaks level × interest (`zan1/base`, `zan1/nol/yoga`)
 * and the 14-10-2026 vote sheet `systema-sanscriticum-lyw-zan1-flag-on_9groups`
 * voted on that same grid, addressing each card as `<level>-<interest>` and
 * collapsing the base profile to just `base`. This class is the ONE place that
 * turns a (level, interest) pair into that group key and asks
 * config('lyw.enabled_groups') whether the pair may be served — so the config
 * never grows a second, parallel group vocabulary.
 *
 * The collapse rule is not invented here either: it mirrors
 * LessonPackRepository::packDir(), where either side being `base` selects the
 * shared `zan1/base` pack.
 *
 * Gate = master switch AND per-group flag. A missing flag is OFF, so a pack
 * dropped into the tree cannot start serving before a human votes it on.
 */
final class LywProfiles
{
    /**
     * Group key for a profile pair: `base` when either side collapses to the
     * shared base pack, otherwise `<level>-<interest>`.
     */
    public static function groupKey(string $level, string $interest): string
    {
        if ($level === 'base' || $interest === 'base') {
            return 'base';
        }

        return $level.'-'.$interest;
    }

    /** Per-group vote flag only (missing key = OFF). */
    public static function groupEnabled(string $level, string $interest): bool
    {
        $groups = (array) config('lyw.enabled_groups', []);

        return (bool) ($groups[self::groupKey($level, $interest)] ?? false);
    }

    /** Master LYW_ENABLED AND the group flag for the pair. */
    public static function enabled(string $level, string $interest): bool
    {
        return (bool) config('lyw.enabled', false) && self::groupEnabled($level, $interest);
    }

    /**
     * The lesson-page tab has no profile params of its own (wave 1), so it opens
     * the default pair; it may render only when that pair is enabled.
     */
    public static function defaultEnabled(): bool
    {
        return self::enabled(
            (string) config('lyw.default_level', 'base'),
            (string) config('lyw.default_interest', 'base'),
        );
    }
}
