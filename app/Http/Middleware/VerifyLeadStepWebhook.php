<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита вебхука «лид дошёл до шага бота»: секрет в заголовке X-Webhook-Secret
 * сверяется с services.n8n.lead_step_secret. Пусто → эндпоинт выключен (403).
 */
final class VerifyLeadStepWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.n8n.lead_step_secret', '');
        $received = (string) $request->header('X-Webhook-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid lead-step webhook secret');
        }

        return $next($request);
    }
}
