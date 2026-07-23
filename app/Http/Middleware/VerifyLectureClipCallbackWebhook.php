<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита callback-вебхука «клипы нарезаны» (n8n → Laravel, H1452): секрет в
 * заголовке X-Webhook-Secret сверяется с services.n8n.clip_callback_secret.
 * Пусто → эндпоинт выключен (403). Тот же паттерн, что VerifyLeadStepWebhook.
 */
final class VerifyLectureClipCallbackWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        // Флаг проверяем раньше секрета: с clip_marketing=OFF маршрут должен
        // вести себя как несуществующий (404), а не как «выключенный» (403).
        abort_if(! config('features.clip_marketing', false), 404);

        $expected = (string) config('services.n8n.clip_callback_secret', '');
        $received = (string) $request->header('X-Webhook-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid lecture-clip callback secret');
        }

        return $next($request);
    }
}
