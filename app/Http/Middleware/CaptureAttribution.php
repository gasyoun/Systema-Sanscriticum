<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запоминает UTM-метки/click_id/реферер первого визита в сессии, чтобы они
 * «дожили» до регистрации/первой оплаты (см. AttributionService::applyToNewUser).
 * Аналог CaptureReferral, но для источника трафика, а не реферального кода:
 * не привязана к конкретному лиду и не перезаписывает уже сохранённые данные.
 */
class CaptureAttribution
{
    public const SESSION_KEY = 'attribution';

    public const UTM_FIELDS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'click_id',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has(self::SESSION_KEY)) {
            $data = [];

            foreach (self::UTM_FIELDS as $field) {
                $value = trim((string) $request->query($field));
                if ($value !== '') {
                    $data[$field] = $value;
                }
            }

            $referrer = trim((string) $request->headers->get('referer'));
            if ($referrer !== '') {
                $data['referrer'] = $referrer;
            }

            if ($data !== []) {
                $request->session()->put(self::SESSION_KEY, $data);
            }
        }

        return $next($request);
    }
}
