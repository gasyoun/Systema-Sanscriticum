<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\IngestCalendarChannelPostJob;
use App\Support\TelegramSendGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Вебхук бота календаря контента (22-10-2026): channel_post из канала-
 * источника → IngestCalendarChannelPostJob. Флаг CONTENT_CAL_TG_INGEST OFF
 * → 404; пустой/неверный секрет (X-Telegram-Bot-Api-Secret-Token) → 403.
 * В запросе только дедуп update_id и диспатч — скачивание медиа в джобе.
 */
class ContentCalendarTelegramWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        abort_unless(config('content_calendar.tg_ingest_enabled') === true, 404);

        $secret = (string) config('content_calendar.tg_webhook_secret');
        abort_if(
            $secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            403
        );

        $update = $request->json()->all();
        $updateId = $update['update_id'] ?? null;
        if ($updateId !== null && ! TelegramSendGuard::claimUpdate('content-calendar', $updateId)) {
            return response()->json(['ok' => true]);
        }

        if (is_array($update['channel_post'] ?? null)) {
            IngestCalendarChannelPostJob::dispatch($update['channel_post']);
        }

        return response()->json(['ok' => true]);
    }
}
