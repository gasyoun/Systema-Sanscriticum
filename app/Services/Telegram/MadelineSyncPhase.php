<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * Breadcrumbs + post-timeout cooldown for telegram-support:sync.
 *
 * After H1915 the watchdog correctly exit(75)s a hung live run. Cleanup then
 * kills the Madeline daemon so a wedged IPC cannot leak FDs. The next minute
 * immediately cold-starts the same DC: if the stall is still there, that run
 * also dies at 120 s. Prod 16–17-08-2026: 18 kills in ~100 min (healthy runs
 * are 11–41 s) — a contained hang, not the H1915 10 470 s leak. This class
 * records the last phase so the timeout log names the hung call, and arms a
 * cooldown so the next live MTProto attempt waits instead of re-entering the
 * death spiral. Does not change the watchdog, the 120 s ceiling, or the
 * kill-on-timeout cleanup.
 *
 * H3380: ключи пер-сессийные ({@see MadelineSessionContext::phaseSuffix()}).
 * Легаси-сессия использует прежние ключи без суффикса; второй аккаунт ведёт
 * свои фазы и свой cooldown — таймаут одного не глушит заходы другого.
 */
final class MadelineSyncPhase
{
    public const PHASE_KEY = 'telegram-support:sync:phase';

    public const COOLDOWN_KEY = 'telegram-support:sync:post-timeout-cooldown';

    public static function mark(string $phase): void
    {
        Cache::put(self::PHASE_KEY.self::keySuffix(), $phase, 180);
    }

    public static function current(): ?string
    {
        $phase = Cache::get(self::PHASE_KEY.self::keySuffix());

        return is_string($phase) && $phase !== '' ? $phase : null;
    }

    public static function armCooldown(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        Cache::put(self::COOLDOWN_KEY.self::keySuffix(), [
            'armed_at' => now()->toIso8601String(),
            'seconds' => $seconds,
            'phase' => self::current(),
        ], $seconds);
    }

    public static function cooldownActive(): bool
    {
        return Cache::has(self::COOLDOWN_KEY.self::keySuffix());
    }

    private static function keySuffix(): string
    {
        return MadelineSessionContext::phaseSuffix();
    }
}
