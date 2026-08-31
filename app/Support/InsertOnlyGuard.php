<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * H3761 — сторож «только вставки» для бэкфила боевых данных.
 *
 * Правило волны 3: команда, которая пишет в прод, обязана уметь ТОЛЬКО
 * вставлять. Ревью кода этого не гарантирует — один `updateOrCreate` в
 * переиспользованном сервисе, и бэкфил молча перепишет то, что уже собрал
 * вебхук. Поэтому запрет проверяется во время выполнения.
 *
 * Слушатель запросов бросает исключение ПРЯМО в момент запрещённого запроса,
 * а не после callback'а: внутри транзакции это откатывает её, так что
 * запрещённая запись не доживает до коммита. Разрешены SELECT/INSERT и
 * служебные команды транзакции.
 *
 * Ограничение Laravel: снять отдельного слушателя `DB::listen` нельзя, поэтому
 * сторож ставится один раз на процесс и «взводится» флагом только на время
 * callback'а — вне него запросы не ограничены.
 */
class InsertOnlyGuard
{
    /** Первое слово запроса, после которого прогон обязан упасть. */
    private const FORBIDDEN = ['update', 'delete', 'truncate', 'replace', 'drop', 'alter'];

    /**
     * ID менеджеров БД, на которые слушатель уже повешен. Ключ именно по
     * объекту, а не булев флаг: приложение может быть пересобрано в том же
     * процессе (каждый тест, воркер очереди, Octane), и статический
     * `$listening = true` тогда врёт — слушателя на новом менеджере нет,
     * а сторож молча пропускает запрещённые запросы.
     *
     * @var array<int, true>
     */
    private static array $listening = [];

    private static bool $armed = false;

    /**
     * Выполнить $callback под запретом на любые не-вставки.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function around(callable $callback)
    {
        $managerId = spl_object_id(DB::getFacadeRoot());

        if (! isset(self::$listening[$managerId])) {
            self::$listening[$managerId] = true;
            DB::listen(function ($query): void {
                if (! self::$armed) {
                    return;
                }

                $verb = strtolower((string) strtok(ltrim($query->sql), " \t\n("));
                if (in_array($verb, self::FORBIDDEN, true)) {
                    // Внутри транзакции исключение откатывает её целиком.
                    throw new RuntimeException(
                        'InsertOnlyGuard: запрещённый запрос в режиме «только вставки»: '.$query->sql
                    );
                }
            });
        }

        $wasArmed = self::$armed;
        self::$armed = true;

        try {
            return $callback();
        } finally {
            self::$armed = $wasArmed;
        }
    }
}
