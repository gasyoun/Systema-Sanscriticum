<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramBusinessUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * H5065 — приём апдейтов Telegram Business.
 *
 * Отвечаем быстро и отдаём апдейт в очередь: у Telegram таймаут 40 с, а разбор
 * business_message включает ingest в support-таблицы и прогон автоответа —
 * ровно та работа, которую нельзя держать на вебхуке.
 *
 * Тело принимается КАК ЕСТЬ (`json()->all()`), без разбора на входе: решение
 * «что это за апдейт» живёт в одном месте — в джобе, где его видно в логах
 * очереди, а не размазано между контроллером и джобой.
 */
final class TelegramBusinessWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        ProcessTelegramBusinessUpdate::dispatch($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
