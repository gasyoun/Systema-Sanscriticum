<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MarketingSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyMaxMagnetWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        // Внимание: Max Bot API при подписке принимает только {url}, без header/body-секрета,
        // поэтому секрет идёт в path. URL хранится только в Max и нашей БД, но всё равно
        // может всплыть в access-логах прокси — ротируйте max_webhook_secret при инцидентах.
        $expected = (string) (MarketingSetting::cached()?->max_webhook_secret ?? '');
        $received = (string) $request->route('secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid Max webhook secret');
        }

        return $next($request);
    }
}
