<?php

namespace App\Console\Concerns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serialise the ONE shared MadelineProto session across the commands that open
 * it: telegram-support:sync, telegram-harvest:sync, telegram-harvest:peers.
 *
 * Two of them opening the session at once spawns a SECOND IPC daemon on the
 * same session directory; the extra daemon steals the IPC sockets and every
 * client then talks to a dead channel (Amp\Ipc\Sync\ChannelException — "Did the
 * context die?"), until the surplus daemon is killed by hand. A single
 * cross-command lock prevents that race. Laravel's own ->withoutOverlapping()
 * only guards a command against ITSELF, not against a different command that
 * shares the session — which is exactly how the two daemons appeared on prod.
 */
trait LocksMadelineSession
{
    /**
     * Run $work while holding the shared session lock. Returns the callback's
     * result, or null when the session is busy (another command holds the lock).
     * The TTL auto-releases if a holder process dies, so a crash can't wedge it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @param  int  $wait  seconds to wait for the lock before giving up as busy
     * @return TReturn|null
     */
    protected function withMadelineSessionLock(callable $work, int $wait = 5): mixed
    {
        $lock = Cache::lock('madeline-session', 900);

        try {
            $lock->block($wait);
        } catch (LockTimeoutException) {
            return null;
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
