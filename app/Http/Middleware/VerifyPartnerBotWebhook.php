<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита API партнёрского бота общим секретом. Enforce-if-configured: если
 * services.partner_bot.secret задан — требуем совпадающий X-Partner-Bot-Secret
 * (сравнение constant-time). Если секрет не настроен — пропускаем (как легаси
 * бот-вебхуки), чтобы локальная разработка не требовала секрета.
 */
class VerifyPartnerBotWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('partner.bot_secret', '');

        if ($expected !== '') {
            $provided = (string) $request->header('X-Partner-Bot-Secret', '');
            if (! hash_equals($expected, $provided)) {
                abort(403, 'Invalid partner-bot secret');
            }
        }

        return $next($request);
    }
}
