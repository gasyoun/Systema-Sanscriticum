<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Подпись легаси Telegram-бот-вебхука (/api/telegram/webhook).
 *
 * Режим «enforce-if-configured»: пока секрет не задан в
 * config(services.telegram.bot_webhook_secret) — пропускаем (поведение как
 * раньше), чтобы не положить живой вебхук до настройки. Как только секрет
 * задан — требуем совпадения заголовка X-Telegram-Bot-Api-Secret-Token
 * (fail-closed, постоянное сравнение).
 *
 * Включение: задать TELEGRAM_BOT_WEBHOOK_SECRET в .env И передать тот же
 * секрет в Telegram через setWebhook(secret_token=...).
 */
final class VerifyTelegramBotWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.telegram.bot_webhook_secret', '');

        // Секрет ещё не настроен — эндпоинт работает как раньше (открыто).
        if ($expected === '') {
            return $next($request);
        }

        $received = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! hash_equals($expected, $received)) {
            abort(403, 'Invalid Telegram webhook secret');
        }

        return $next($request);
    }
}
