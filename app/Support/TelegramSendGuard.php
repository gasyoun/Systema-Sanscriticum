<?php

declare(strict_types=1);

namespace App\Support;

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
 */
final class TelegramSendGuard
{
    /** Окно подавления дублей. Суточное расписание укладывается с запасом. */
    private const TTL_SECONDS = 86400;

    public static function claim(string $chatId, string $text): bool
    {
        return (bool) Redis::set(self::key($chatId, $text), now()->toIso8601String(), 'EX', self::TTL_SECONDS, 'NX');
    }

    public static function release(string $chatId, string $text): void
    {
        Redis::del(self::key($chatId, $text));
    }

    public static function key(string $chatId, string $text): string
    {
        return 'tg:once:'.hash('sha256', $chatId."\x00".$text);
    }
}
