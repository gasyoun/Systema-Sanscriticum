<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * H4663 (аудит периметра 14-09, п.10): API-роуты без Accept: application/json
     * раньше отвечали 302 на HTML-страницу /login вместо 401 JSON — мобильный
     * клиент получал редирект. Для api-группы всегда JSON-401.
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        return route('login');
    }
}
