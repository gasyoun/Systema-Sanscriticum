<?php

namespace App\Console\Concerns;

use App\Services\Telegram\MadelineSessionContext;
use App\Services\Telegram\MadelineSyncWatchdog;
use Illuminate\Contracts\Cache\Lock;
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
 *
 * H3380: имя замка пер-сессийное ({@see MadelineSessionContext::lockName()}).
 * Для легаси-сессии по умолчанию это прежний 'madeline-session' — сериализация
 * support-sync ↔ harvest-sync не меняется. Второй аккаунт (rusamskrtam) получает
 * собственный замок и НЕ спорит с первым о владении.
 */
trait LocksMadelineSession
{
    /**
     * Замок текущего захода — нужен, чтобы его мог отпустить обработчик таймаута
     * {@see MadelineSyncWatchdog}: тот завершает процесс
     * через exit(), поэтому `finally` ниже НЕ отработает (H1915).
     */
    private ?Lock $madelineSessionLock = null;

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
        $lock = $this->madelineSessionLock = Cache::lock(MadelineSessionContext::lockName(), 900);

        try {
            $lock->block($wait);
        } catch (LockTimeoutException) {
            $this->madelineSessionLock = null;

            return null;
        }

        try {
            return $work();
        } finally {
            $this->releaseMadelineSessionLock();
        }
    }

    /**
     * Отпускает замок сессии, если он у нас. Идемпотентно — вызывается и из
     * обычного `finally`, и из уборки watchdog'а перед exit().
     *
     * Без этого вызова в уборке замок провисел бы свои 900 с TTL и заблокировал
     * бы ~15 минут заходов после КАЖДОГО срабатывания потолка.
     */
    protected function releaseMadelineSessionLock(): void
    {
        $lock = $this->madelineSessionLock;
        $this->madelineSessionLock = null;

        $lock?->release();
    }
}
