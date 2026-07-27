<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Exceptions\MadelineSyncTimedOut;

/**
 * Жёсткий потолок времени для одного захода MTProto-синка.
 *
 * Зачем. `telegram-support:sync` ходит в сеть через amphp и умеет зависнуть
 * навсегда (мёртвый IPC-канал, не отвечающий DC). Планировщик защищён только
 * `->withoutOverlapping($ttl)`, а этот замок ПРОТУХАЕТ по TTL — после чего
 * стартует второй экземпляр на той же сессии, хотя первый ещё жив. 27.07.2026
 * на проде так накопилось десять параллельных синков, каждый со своим
 * IPC-демоном; они добили процесс до лимита открытых файлов (EMFILE, 1024).
 * Поэтому заход обязан умирать сам, раньше протухания замка.
 *
 * Как. `pcntl_alarm()` + асинхронная доставка сигналов: обработчик БРОСАЕТ
 * {@see MadelineSyncTimedOut} вместо `exit()` — иначе `finally` не отработал бы
 * и кэш-замок `madeline-session` (TTL 900 с) остался бы висеть на 15 минут,
 * заблокировав следующие заходы. Исключение раскручивает стек штатно.
 *
 * Без расширения pcntl (в т.ч. на Windows) watchdog — честный no-op: {@see arm()}
 * вернёт false, и вызывающий сам решает, шуметь ли об этом.
 */
class MadelineSyncWatchdog
{
    private bool $armed = false;

    /**
     * Взводит будильник на $seconds. Возвращает false, если таймаут отключён
     * ($seconds <= 0) или pcntl недоступен — тогда заход идёт без потолка.
     */
    public function arm(int $seconds): bool
    {
        if ($seconds <= 0 || ! $this->isSupported()) {
            return false;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($seconds): void {
            throw new MadelineSyncTimedOut($seconds);
        });
        pcntl_alarm($seconds);

        $this->armed = true;

        return true;
    }

    /**
     * Снимает будильник (заход завершился сам). Идемпотентно.
     */
    public function disarm(): void
    {
        if (! $this->armed) {
            return;
        }

        pcntl_alarm(0);
        $this->armed = false;
    }

    public function isSupported(): bool
    {
        return PHP_OS_FAMILY !== 'Windows'
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_alarm')
            && function_exists('pcntl_signal');
    }
}
