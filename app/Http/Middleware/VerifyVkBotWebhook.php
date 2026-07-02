<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Секрет легаси VK-бот-вебхука (/api/vk-webhook).
 *
 * Fail-CLOSED: если секрет не задан в config(services.vk.callback_secret) —
 * запрос отклоняется (403). Открытый режим убрали: без секрета кто угодно мог
 * подделывать сообщения студентов и злоупотреблять платным ИИ-куратором.
 *
 * VK-confirmation приходит БЕЗ секрета — пропускаем всегда, иначе VK не сможет
 * подтвердить адрес callback-сервера (контроллер вернёт confirm_code).
 *
 * ⚠️ Деплой: перед выкаткой задать VK_CALLBACK_SECRET в .env И тот же секрет в
 * настройках Callback API группы VK, иначе живой вебхук начнёт отвечать 403.
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

        // Секрет обязателен: без него нельзя отличить настоящий VK от подделки.
        if ($expected === '') {
            abort(403, 'VK callback secret is not configured');
        }

        $received = (string) $request->input('secret', '');

        if (! hash_equals($expected, $received)) {
            abort(403, 'Invalid VK callback secret');
        }

        return $next($request);
    }
}
