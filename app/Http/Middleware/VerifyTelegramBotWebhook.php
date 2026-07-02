<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Подпись легаси Telegram-бот-вебхука (/api/telegram/webhook).
 *
 * Fail-CLOSED: если секрет не задан в config(services.telegram.bot_webhook_secret)
 * — запрос отклоняется (403). Открытый режим убрали: без секрета кто угодно мог
 * слать поддельные апдейты (спуфинг сообщений студента, вызовы платного ИИ,
 * спам админ-алертов).
 *
 * ⚠️ Деплой: перед выкаткой задать TELEGRAM_BOT_WEBHOOK_SECRET в .env И передать
 * тот же секрет в Telegram через setWebhook(secret_token=...), иначе живой вебхук
 * начнёт отвечать 403.
 */
final class VerifyTelegramBotWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.telegram.bot_webhook_secret', '');

        // Секрет обязателен: без него нельзя отличить настоящий Telegram от подделки.
        if ($expected === '') {
            abort(403, 'Telegram webhook secret is not configured');
        }

        $received = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! hash_equals($expected, $received)) {
            abort(403, 'Invalid Telegram webhook secret');
        }

        return $next($request);
    }
}
