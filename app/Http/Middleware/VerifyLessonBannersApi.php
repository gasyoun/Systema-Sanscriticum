<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита API плашек занятий (n8n «Плашки занятий» ↔ Laravel): секрет в
 * заголовке X-Webhook-Secret сверяется с services.n8n.lesson_banners_secret.
 * Пусто → эндпоинты выключены (403). Тот же паттерн, что
 * VerifyLectureClipCallbackWebhook.
 */
final class VerifyLessonBannersApi
{
    public function handle(Request $request, Closure $next): Response
    {
        // Флаг раньше секрета: с lesson_banners=OFF маршрут ведёт себя как
        // несуществующий (404), а не как «выключенный» (403).
        abort_if(! config('features.lesson_banners', false), 404);

        $expected = (string) config('services.n8n.lesson_banners_secret', '');
        $received = (string) $request->header('X-Webhook-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid lesson-banners secret');
        }

        return $next($request);
    }
}
