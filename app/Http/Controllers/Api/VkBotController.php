<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVkBotMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VkBotController extends Controller
{
    /**
     * Callback API легаси VK-бота. Отвечает «ok» сразу: вся обработка (привязка,
     * ИИ-куратор) — в очереди (ProcessVkBotMessage). Синхронный LLM-вызов здесь
     * приводил к ретраям VK (не дождался «ok») и дублям ответов студенту.
     */
    public function handle(Request $request)
    {
        $data = $request->all();

        // Логируем только тип события — без полного payload (в message_new лежит
        // текст переписки студента, который не должен копиться в файловых логах).
        Log::info('VK webhook получен', ['type' => $data['type'] ?? null]);

        // 1. ПОДТВЕРЖДЕНИЕ СЕРВЕРА ДЛЯ ВК
        if (($data['type'] ?? '') === 'confirmation') {
            // Принудительно отдаем как простой текст без HTML и JSON
            return response((string) config('services.vk.confirm_code'), 200)
                ->header('Content-Type', 'text/plain');
        }

        // 2. НОВОЕ СООБЩЕНИЕ — валидируем, дедуплицируем, ставим в очередь.
        if (($data['type'] ?? '') === 'message_new') {
            $messageObj = $data['object']['message'] ?? null;
            $vkId = is_array($messageObj) ? ($messageObj['from_id'] ?? null) : null;

            // Малформированный payload (битый callback / сканер / replay): отвечаем 200
            // чтобы VK не уходил в бесконечный retry, и логируем для диагностики.
            if (! is_int($vkId) && ! ctype_digit((string) $vkId)) {
                // Не логируем весь payload (может содержать текст сообщения) — только факт.
                Log::warning('VK webhook: malformed message_new payload');

                return response('ok', 200);
            }

            // Дедупликация повторных доставок: VK ретраит событие, если не получил
            // «ok» вовремя (пиковая нагрузка, рестарт fpm). Атомарный Cache::add
            // отдаёт обработку только первой доставке сообщения.
            $messageKey = $messageObj['conversation_message_id']
                ?? $messageObj['id']
                ?? md5(($messageObj['text'] ?? '').'|'.($messageObj['date'] ?? ''));

            if (! Cache::add("vk_bot_msg:{$vkId}:{$messageKey}", true, 600)) {
                return response('ok', 200);
            }

            ProcessVkBotMessage::dispatch($messageObj);
        }

        return response('ok', 200);
    }
}
