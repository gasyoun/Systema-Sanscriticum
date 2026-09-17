<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * H5065 — secret verification for the Telegram Business webhook.
 *
 * Те же два правила, что у zapisi/magnet-вебхуков, и оба fail-closed:
 *  - флаг полосы OFF → 404 (эндпоинт не существует, а не «принимаем в тишину»);
 *  - пустой настроенный секрет = «не настроено», никогда не «пропускаем
 *    проверку»; несовпадение → 403.
 *
 * Секрет сравнивается hash_equals: заголовок приходит извне, а обычное `===`
 * на строках даёт timing-оракул.
 */
final class VerifyTelegramBusinessWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('features.telegram_business_bot', false)) {
            abort(404);
        }

        $expected = trim((string) config('services.telegram_business.secret', ''));
        $received = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid Telegram Business webhook secret');
        }

        return $next($request);
    }
}
