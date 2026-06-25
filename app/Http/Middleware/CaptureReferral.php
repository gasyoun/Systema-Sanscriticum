<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запоминает реферальный код из ?ref=CODE в сессии, чтобы он «дожил» от перехода
 * по ссылке-приглашению до момента регистрации/оплаты (где ReferralService его
 * подхватывает). Не перезаписывает уже сохранённый код.
 */
class CaptureReferral
{
    public function handle(Request $request, Closure $next): Response
    {
        $ref = trim((string) $request->query('ref'));

        if ($ref !== '' && ! $request->session()->has('ref')) {
            $request->session()->put('ref', $ref);
        }

        return $next($request);
    }
}
