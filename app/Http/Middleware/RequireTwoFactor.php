<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * H6352-r5 (рулинг 7 грилла 10-10): админ-роль без доказанного второго фактора
 * не ходит дальше страницы 2FA. Гейт стоит и в группе `web`, и в стеке обоих
 * Filament-панелей (панель не берёт группу `web`), поэтому Livewire-запросы
 * панели тоже закрыты, а не только HTML-страницы.
 *
 * Пропускаются: маршруты самого гейта (`two-factor.*`), оба выхода и
 * неаутентифицированные запросы. Студенты/преподаватели не гейтятся.
 */
class RequireTwoFactor
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->twoFactor->requiredFor($user)) {
            return $next($request);
        }

        if ($this->twoFactor->passed($request, $user)) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.*', 'logout', 'filament.*.auth.logout')) {
            return $next($request);
        }

        return redirect()->route('two-factor.show');
    }
}
