<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Секрет легаси VK-бот-вебхука (/api/vk-webhook).
 *
 * Режим «enforce-if-configured»: пока секрет не задан в
 * config(services.vk.callback_secret) — пропускаем (поведение как раньше).
 * Как только секрет задан — требуем body-поле `secret` (fail-closed).
 *
 * VK-confirmation приходит БЕЗ секрета — пропускаем всегда, иначе VK не сможет
 * подтвердить адрес callback-сервера (контроллер вернёт confirm_code).
 *
 * Включение: задать VK_CALLBACK_SECRET в .env И тот же секрет в настройках
 * Callback API группы VK.
 */
final class VerifyVkBotWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        // Confirmation-handshake идёт без секрета.
        if (($request->input('type') ?? '') === 'confirmation') {
            return $next($request);
        }

        $expected = (string) config('services.vk.callback_secret', '');

        // Секрет ещё не настроен — эндпоинт работает как раньше (открыто).
        if ($expected === '') {
            return $next($request);
        }

        $received = (string) $request->input('secret', '');

        if (! hash_equals($expected, $received)) {
            abort(403, 'Invalid VK callback secret');
        }

        return $next($request);
    }
}
