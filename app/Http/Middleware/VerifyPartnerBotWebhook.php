<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита API партнёрского бота общим секретом. Когда программа
 * включена, секрет обязателен (503 при ошибке конфигурации), а заголовок
 * X-Partner-Bot-Secret сравнивается constant-time. При выключенной программе
 * запрос доходит до контроллера, который сохраняет прежний 404.
 */
class VerifyPartnerBotWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('partner.enabled', false)) {
            return $next($request);
        }

        $expected = trim((string) config('partner.bot_secret', ''));

        if ($expected === '') {
            abort(503, 'Partner-bot secret is not configured');
        }

        $provided = (string) $request->header('X-Partner-Bot-Secret', '');
        if (! hash_equals($expected, $provided)) {
            abort(403, 'Invalid partner-bot secret');
        }

        return $next($request);
    }
}
