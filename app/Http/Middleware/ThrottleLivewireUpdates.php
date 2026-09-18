<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * H5087 (remediation of H5046 checkout-promo-remove-unthrottled-post):
 * Livewire v3 сам регистрирует POST /livewire/update с группой web и без
 * throttle на каком-либо слое — анонимный реплей снапшота публичного
 * компонента (shop.course-catalog на /online) перерисовывает каталог с
 * SQL-веером на каждый хит и минтит file-session на безкукисных хитах.
 *
 * Обёртка применяет именованный лимитер 'livewire-update' ТОЛЬКО к
 * livewire/update; прочие web-запросы проходят без изменений (лимитер —
 * в RouteServiceProvider: анонимам жёсткий IP-лимит, залогиненным запас
 * под Filament-UI с дебаунсом).
 */
class ThrottleLivewireUpdates
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('livewire/update')) {
            return app(ThrottleRequests::class)->handle($request, $next, 'livewire-update');
        }

        return $next($request);
    }
}
