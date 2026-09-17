<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * H5087 (аудит H5046, low confirmed): Livewire v3 сам регистрирует
 * POST /livewire/update с группой web и без throttle на каком-либо слое
 * (vendor HandleRequests::boot — до загрузки маршрутов приложения, поэтому
 * переопределение маршрута из AppServiceProvider не перебивает дефолт).
 * Анонимный реплей /livewire/update на публичной странице /online
 * перерисовывает shop.course-catalog с полным Course-фанаутом на каждый хит.
 *
 * Обёртка: именованный лимитер 'livewire' применяется ТОЛЬКО к
 * livewire/update — прочие web-запросы проходят без изменений.
 */
class ThrottleLivewireUpdates
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('livewire/update')) {
            return app(ThrottleRequests::class)->handle($request, $next, 'livewire');
        }

        return $next($request);
    }
}
