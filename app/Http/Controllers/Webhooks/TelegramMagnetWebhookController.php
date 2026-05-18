<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramMagnetUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramMagnetWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // Отвечаем сразу — Telegram таймаут 40 сек, работаем через очередь.
        ProcessTelegramMagnetUpdate::dispatch($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
