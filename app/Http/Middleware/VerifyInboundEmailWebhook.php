<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * H3462: защита вебхука входящего email (POST /api/webhooks/inbound-email/{secret}).
 * Секрет идёт в пути URL — как у verify.max.magnet: проводник пересылки
 * (n8n на .91) не всегда умеет кастомные заголовки, а путь выдерживает любой
 * HTTP-клиент. Пустой секрет в конфиге → эндпоинт выключен (403), fail-closed.
 */
final class VerifyInboundEmailWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.inbound_email.webhook_secret', '');
        $received = (string) $request->route('secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid inbound email webhook secret');
        }

        return $next($request);
    }
}
