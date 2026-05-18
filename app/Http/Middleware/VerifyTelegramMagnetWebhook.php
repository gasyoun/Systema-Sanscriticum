<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MarketingSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTelegramMagnetWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) (MarketingSetting::first()?->tg_webhook_secret ?? '');
        $received = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid Telegram webhook secret');
        }

        return $next($request);
    }
}
