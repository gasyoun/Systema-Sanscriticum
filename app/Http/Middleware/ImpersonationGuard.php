<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Границы режима просмотра за пользователя (H1947). Стоит и в группе `web`, и в
 * middleware панели Filament — у панели свой стек, группу `web` она не включает,
 * так что одной регистрации не хватило бы.
 *
 * Три обязанности:
 *  1. Fail-closed: флаг выключили, пока режим открыт → режим закрывается на
 *     первом же запросе, а не живёт до конца сессии.
 *  2. Денежные записи в режиме → 403 + запись в лог.
 *  3. Плашка режима подмешивается в HTML на выходе — так она появляется на ВСЕХ
 *     макетах (кабинет, витрина, Filament) без правки каждого из них.
 */
final class ImpersonationGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::isActive() && ! Impersonation::enabled()) {
            $restored = Impersonation::stop($request);

            return redirect()->to($restored ? '/admin' : '/');
        }

        if (Impersonation::isActive() && Impersonation::isBlockedWrite($request)) {
            Log::warning('impersonation.blocked_write', [
                'impersonator_id' => session()->get(Impersonation::SESSION_IMPERSONATOR),
                'acting_as_id' => auth()->id(),
                'mode' => Impersonation::mode(),
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'uri' => $request->path(),
            ]);

            abort(403, 'В режиме просмотра за пользователя денежные действия запрещены. Выйдите из режима и повторите от своего имени.');
        }

        return $this->injectBanner($request, $next($request));
    }

    private function injectBanner(Request $request, Response $response): Response
    {
        if (! Impersonation::isActive() || ! $response instanceof HttpResponse) {
            return $response;
        }

        // Livewire/AJAX-ответы — это фрагменты, плашка в них не нужна: она уже
        // отрисована полной загрузкой страницы.
        if ($request->ajax() || $request->wantsJson() || $request->hasHeader('X-Livewire')) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content)) {
            return $response;
        }

        $position = strripos($content, '</body>');

        if ($position === false) {
            return $response;
        }

        $banner = view('partials.impersonation-banner', [
            'mode' => Impersonation::mode(),
            'actingAs' => auth()->user(),
            'impersonator' => Impersonation::impersonator(),
            'height' => (int) config('impersonation.banner_height', 46),
        ])->render();

        $response->setContent(substr_replace($content, $banner, $position, 0));

        return $response;
    }
}
