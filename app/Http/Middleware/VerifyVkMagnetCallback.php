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
        // VK при первой настройке шлёт confirmation-запрос — пропускаем без секрета.
        if (($request->input('type') ?? '') === 'confirmation') {
            return $next($request);
        }

        $expected = (string) (MarketingSetting::first()?->vk_callback_secret ?? '');
        $received = (string) $request->input('secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid VK callback secret');
        }

        return $next($request);
    }
}
