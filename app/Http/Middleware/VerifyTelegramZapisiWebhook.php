<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MarketingSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Track C (H164, D8): secret verification for @zapisi_ORSbot's webhook.
 * Same fail-closed pattern as VerifyTelegramMagnetWebhook — an empty
 * configured secret means "not configured", never "skip verification".
 */
final class VerifyTelegramZapisiWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) (MarketingSetting::cached()?->zapisi_webhook_secret ?? '');
        $received = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid Telegram webhook secret');
        }

        return $next($request);
    }
}
