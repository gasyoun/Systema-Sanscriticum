<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PartnerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запоминает партнёрский код из ?pref=CODE в сессии (last-touch), чтобы он
 * «дожил» от перехода по партнёрской ссылке до момента, когда клиент
 * авторизуется. Как только клиент залогинен и ещё не привязан к партнёру —
 * привязываем (PartnerService). Отдельный ключ сессии `pref` от студенческого
 * `ref` (CaptureReferral) — программы независимы. No-op, если программа выключена.
 */
class CapturePartnerReferral
{
    public function handle(Request $request, Closure $next): Response
    {
        $pref = trim((string) $request->query('pref'));

        if ($pref !== '' && ! $request->session()->has('pref')) {
            $request->session()->put('pref', $pref);
        }

        // Как только у нас есть залогиненный клиент и сохранённый код — привязываем.
        if ($request->user() && $request->session()->has('pref')) {
            app(PartnerService::class)->attachPartner($request->user(), $request->session()->get('pref'));
        }

        return $next($request);
    }
}
