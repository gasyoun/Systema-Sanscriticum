<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramZapisiUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Track C (H164, D8): go-forward capture for @zapisi_ORSbot. Answers fast
 * (Telegram's 40s timeout) and hands the update to a queued job — same shape
 * as TelegramMagnetWebhookController.
 */
final class TelegramZapisiWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        ProcessTelegramZapisiUpdate::dispatch($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
