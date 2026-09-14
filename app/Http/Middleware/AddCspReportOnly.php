<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * H4663 (аудит периметра 14-09, п.7): CSP только в режиме Report-Only —
 * наблюдение без блокировки. Стоит в web-группе (Kernel); Filament-панели
 * несут свой стек — для них Report-Only добавляется следом за ужесточением
 * (GTD H4663). Отдельный класс, а не closure в группе: MiddlewareNameResolver
 * не умеет резолвить Closure в middleware-группах (TypeError на тестах CI).
 */
class AddCspReportOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->has('Content-Security-Policy-Report-Only')) {
            return $response;
        }

        $response->headers->set(
            'Content-Security-Policy-Report-Only',
            "default-src 'self'; img-src 'self' data: https:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; frame-ancestors 'self' https://samskrtam.ru; report-uri /csp-report"
        );

        return $response;
    }
}
