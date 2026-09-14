<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Идемпотентная отправка Telegram-сообщений (анти-дубль).
 *
 * Гарантия: один и тот же (chat_id, текст) уходит в Telegram НЕ БОЛЕЕ ОДНОГО
 * раза за окно TTL — сколько бы раз джоб ни ретраился и сколько бы источников
 * ни пытались отправить то же самое.
 *
 * Зачем: дедуп уровня команды расписания (zapisi_reminded_at) стоит при
 * ДИСПАТЧЕ и не защищает от повторов ниже по цепочке — классический случай:
 * Telegram принял сообщение, но ответ потерялся (таймаут/обрыв) → джоб
 * ретраится через 15 сек → в чат уходят две одинаковые копии подряд.
 *
 * Контракт вызова в джобе:
 *  1) claim() перед отправкой: false → уже кто-то послал, молча пропускаем;
 *  2) успех → ничего не делаем (ключ держит окно);
 *  3) Telegram ОТВЕТИЛ отказом (4xx/5xx — доставка точно НЕ случилась) →
 *     release() + бросить исключение (бэкофф-ретрай безопасен);
 *  4) транспортный сбой без ответа (не соединились / таймаут / обрыв — Guzzle
 *     и Laravel сообщают это одним классом ConnectionException, различить
 *     «запрос не ушёл» и «ответ потерян после отправки» нельзя) → ключ НЕ
 *     отпускать: подавленный ретрай дешевле возможного дубля в чат.
 *
 * Итог: дубликаты невозможны; ценой является редкая потеря сообщения при
 * транспортном сбое — каждая такая потеря фиксируется warning'ом в логе.
 * Исключение — недоступный Redis: клейм тогда fail-open (шлём без защиты,
 * громкий warning), потому что дедуп — оптимизация против дублей, а не гейт
 * доставки.
 */
final class TelegramSendGuard
{
    /** Окно подавления дублей контента. Суточное расписание укладывается с запасом. */
    private const TTL_SECONDS = 86400;

    /** Окно подавления повторных апдейтов вебхука/поллера (ределивери Telegram). */
    private const UPDATE_TTL_SECONDS = 3600;

    public static function claim(string $chatId, string $text): bool
    {
        return self::claimKey(self::key($chatId, $text), self::TTL_SECONDS);
    }

    public static function release(string $chatId, string $text): void
    {
        try {
            Redis::del(self::key($chatId, $text));
        } catch (\Throwable $exception) {
            Log::warning('TelegramSendGuard: release failed (redis unavailable)', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public static function key(string $chatId, string $text): string
    {
        return 'tg:once:'.hash('sha256', $chatId."\x00".$text);
    }

    /**
     * Клейм по произвольному ключу — для дедупа НЕ контентных сущностей
     * (update_id вебхука и т.п.). false = уже видели, обрабатывать не надо.
     *
     * FAIL-OPEN при недоступном Redis: дедуп — защита от дублей, а не гейт
     * доставки. В проде очередь стоит вместе с Redis, но вебхук-дорожка может
     * работать и без него — сообщение лучше отправить без защиты, чем потерять;
     * деградация фиксируется громким warning'ом.
     */
    public static function claimKey(string $key, int $ttlSeconds = self::UPDATE_TTL_SECONDS): bool
    {
        try {
            return (bool) Redis::set($key, now()->toIso8601String(), 'EX', max(1, $ttlSeconds), 'NX');
        } catch (\Throwable $exception) {
            Log::warning('TelegramSendGuard: redis unavailable, dedup disabled for this send', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Дедуп входящего Telegram-апдейта по update_id: ределивери вебхука после
     * медленного/упавшего обработчика и повторный приём поллером не должны
     * приводить к повторной обработке (двойной ответ бота, двойной форвард).
     */
    public static function claimUpdate(string $scope, int|string $updateId): bool
    {
        return self::claimKey('tg:upd:'.$scope.':'.$updateId);
    }
}
