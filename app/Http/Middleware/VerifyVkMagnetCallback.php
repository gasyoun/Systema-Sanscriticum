<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MarketingSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyVkMagnetCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = MarketingSetting::cached();

        // VK при первой настройке шлёт confirmation-запрос без секрета.
        // Пропускаем только если confirmation ещё не выполнен — иначе
        // любой неаутентифицированный POST сможет получить confirmation-код
        // и зарегистрировать наш сервер как webhook чужой группы.
        // Чтобы повторно подтвердить (новый URL), админ обнуляет поле руками.
        if (($request->input('type') ?? '') === 'confirmation') {
            if ($settings?->vk_confirmation_completed_at !== null) {
                abort(403, 'VK confirmation already completed');
            }

            return $next($request);
        }

        $expected = (string) ($settings?->vk_callback_secret ?? '');
        $received = (string) $request->input('secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid VK callback secret');
        }

        return $next($request);
    }
}
