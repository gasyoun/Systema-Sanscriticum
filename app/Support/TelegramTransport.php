<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Единый контракт «внешний вызов Telegram не имеет права ломать вызывающий
 * запрос» (прод-инцидент 25-09-2026).
 *
 * Что было: HTTP-вызовы к api.telegram.org шли прямо из пользовательских
 * запросов без захвата исключений и без таймаутов. Дефолты клиента Laravel
 * (connect_timeout=10, timeout=30, vendor/.../PendingRequest.php) означали, что
 * при недоступном Telegram (DNS отдаёт недоступные IP, SYN уходит в чёрную
 * дыру) запрос держал FPM-воркер до 10 с и падал 500. На пуле из 12 воркеров
 * этого хватало, чтобы уронить сайт (9 срабатываний pm.max_children за сутки).
 *
 * Теперь все такие вызовы идут через post(): короткие таймауты, исключение
 * транспорта не летит наружу (возвращается null), причина уходит в лог
 * санитизированной — без токена бота и без персональных данных.
 */
final class TelegramTransport
{
    /**
     * Connect рвётся за 2 с: типовой прод-случай — недоступный адрес, и ждать
     * здесь нечего. Весь вызов ограничен 5 с: служебное сообщение не стоит
     * воркера дороже (в очередях свой бюджет — connect 5 / total 15).
     */
    public const CONNECT_TIMEOUT_SECONDS = 2;

    public const TIMEOUT_SECONDS = 5;

    /** Клиент с короткими таймаутами — для вызовов, которым нужен свой разбор ответа. */
    public static function client(): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * POST, который не бросает.
     *
     * Возврат:
     *  - null — транспортный сбой (нет соединения, таймаут, обрыв): Telegram
     *    недоступен, вызывающему стоит прекратить попытки в этом запросе;
     *  - Response с любым статусом — Telegram ответил (2xx или отказ): отказ
     *    логируется как error, но решение о поведении остаётся за вызывающим.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context  для лога: id чата и прочее, но не текст сообщения
     */
    public static function post(string $url, array $payload, string $event, array $context = []): ?Response
    {
        try {
            $response = self::client()->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning($event.': Telegram недоступен, вызов пропущен', $context + [
                'error' => self::sanitize($e->getMessage()),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::error($event.': Telegram ответил отказом', $context + [
                'status' => $response->status(),
                'body' => self::sanitize($response->body()),
            ]);
        }

        return $response;
    }

    /**
     * Токен бота нельзя пускать в лог: Laravel/curl кладут в текст ошибки
     * соединения весь URL, а в нём `https://api.telegram.org/bot<digits>:<token>/...`.
     * Тем же способом чистим тело ответа: его может подменить промежуточная
     * страница (блок-страница фильтрующей среды) и вернуть тот же URL.
     */
    public static function sanitize(string $message): string
    {
        return (string) preg_replace('#bot\d+:[A-Za-z0-9_\-]+#i', 'bot[redacted]', $message);
    }
}
