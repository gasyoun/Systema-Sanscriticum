<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Models\LandingBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramMagnetWebhookController extends Controller
{
    /** Глобальный бот из MarketingSetting (обратная совместимость). */
    public function handle(Request $request): JsonResponse
    {
        // Отвечаем сразу — Telegram таймаут 40 сек, работаем через очередь.
        ProcessTelegramMagnetUpdate::dispatch($request->json()->all());

        return response()->json(['ok' => true]);
    }

    /** Бот конкретного лендинга — резолвлен и провалидирован в middleware. */
    public function handlePerBot(Request $request): JsonResponse
    {
        /** @var LandingBot|null $bot */
        $bot = $request->attributes->get('landing_bot');

        ProcessTelegramMagnetUpdate::dispatch($request->json()->all(), $bot?->id);

        return response()->json(['ok' => true]);
    }
}
