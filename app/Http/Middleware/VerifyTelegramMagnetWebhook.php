<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\LandingBot;
use App\Models\MarketingSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTelegramMagnetWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        // Per-bot эндпоинт: секрет берём у бота лендинга по ключу из URL.
        // Резолвнутого бота кладём в attributes — контроллеру не нужен второй запрос.
        $webhookKey = $request->route('webhookKey');

        if ($webhookKey !== null) {
            $bot = LandingBot::where('webhook_key', $webhookKey)
                ->where('is_active', true)
                ->first();

            if (! $bot) {
                abort(404, 'Unknown bot');
            }

            $expected = (string) ($bot->tg_webhook_secret ?? '');
            $request->attributes->set('landing_bot', $bot);
        } else {
            // Глобальный бот из MarketingSetting (обратная совместимость).
            $expected = (string) (MarketingSetting::cached()?->tg_webhook_secret ?? '');
        }

        $received = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            abort(403, 'Invalid Telegram webhook secret');
        }

        return $next($request);
    }
}
