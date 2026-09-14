<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        // H4691: every post-timeout cooldown IS a watchdog kill — count it in
        // the shared breaker, whose budget decides when the loop must stop.
        MadelineSyncBreaker::recordKill();
    }

    /**
     * true = do not start a live MTProto run now. Two gates, one answer
     * (H4691 cooldown/breaker alignment): the short post-kill cooldown, and the
     * sentinel breaker frozen after too many kills (freeze + Telegram scream +
     * auto-unfreeze live in the shared library, see MadelineSyncBreaker).
     */
    public static function cooldownActive(): bool
    {
        if (Cache::has(self::COOLDOWN_KEY.self::keySuffix())) {
            return true;
        }
        if (MadelineSyncBreaker::frozen()) {
            Log::warning('MadelineSync live run skipped: sentinel breaker frozen (too many watchdog kills).', [
                'guardian' => MadelineSyncBreaker::guardian(),
            ]);

            return true;
        }

        return false;
    }

    private static function keySuffix(): string
    {
        return MadelineSessionContext::phaseSuffix();
    }
}
