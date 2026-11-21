<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защита GET /api/n8n/vk-token (n8n «Рилсы — автопостинг» забирает действующий
 * VK-токен перед загрузкой видео): секрет в X-Webhook-Secret сверяется с
 * services.n8n.vk_token_secret. Пусто → эндпоинт выключен (403).
 * Тот же паттерн, что VerifyLessonBannersApi.
 */
final class VerifyVkTokenApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.n8n.vk_token_secret', '');
        $received = (string) $request->header('X-Webhook-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid vk-token secret');
        }

        return $next($request);
    }
}
