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
        $expected = (string) (MarketingSetting::first()?->max_webhook_secret ?? '');
        $received = (string) $request->route('secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid Max webhook secret');
        }

        return $next($request);
    }
}
