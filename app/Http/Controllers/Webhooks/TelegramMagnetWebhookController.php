<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Models\LandingBot;
use App\Support\TelegramSendGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class TelegramMagnetWebhookController extends Controller
{
    /** Глобальный бот из MarketingSetting (обратная совместимость). */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        // Отвечаем сразу — Telegram таймаут 40 сек, работаем через очередь.
        if ($this->claimUpdate($payload, 'magnet')) {
            ProcessTelegramMagnetUpdate::dispatch($payload);
        }

        return response()->json(['ok' => true]);
    }

    /** Бот конкретного лендинга — резолвлен и провалидирован в middleware. */
    public function handlePerBot(Request $request): JsonResponse
    {
        /** @var LandingBot|null $bot */
        $bot = $request->attributes->get('landing_bot');
        $payload = $request->json()->all();

        if ($this->claimUpdate($payload, 'magnet:bot:'.($bot?->id ?? 'none'))) {
            ProcessTelegramMagnetUpdate::dispatch($payload, $bot?->id);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Клейм по update_id — тот же контракт at-most-once, что у главного
     * вебхука (/api/telegram/webhook) и ProcessTelegramZapisiUpdate.
     *
     * Telegram пересылает апдейт повторно, если обработчик ответил медленно
     * или соединение оборвалось. Раньше этот эндпоинт просто диспатчил джобу,
     * поэтому ределивери давал двойной форвард в n8n и двойной ответ студенту
     * (прод 13-11-2026, 18:02: две копии «✅ Вы приняли политику
     * конфиденциальности» в одну минуту). Повтор молча подтверждаем «ok».
     *
     * update_id уникален только ВНУТРИ одного бота, поэтому у per-bot ботов
     * своё пространство ключей, отдельное от глобального. Без update_id
     * (нестандартный отправитель) и при недоступном Redis — fail-open: дедуп
     * не имеет права терять сообщение.
     */
    private function claimUpdate(array $payload, string $scope): bool
    {
        $updateId = $payload['update_id'] ?? null;

        if (! is_numeric($updateId)) {
            return true;
        }

        if (TelegramSendGuard::claimUpdate($scope, (int) $updateId)) {
            return true;
        }

        Log::info('TelegramMagnetWebhook: update already processed, duplicate suppressed', [
            'scope' => $scope,
            'update_id' => (int) $updateId,
        ]);

        return false;
    }
}
