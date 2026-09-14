<?php

declare(strict_types=1);

namespace App\Services\Access;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка служебных сообщений админам в Telegram (H849) — с опциональной
 * inline-клавиатурой. Отдельно от TelegramWebhookController::sendAdminAlert,
 * который inline-кнопки не умеет; повторяет его выбор бота (основной bot_token)
 * и список получателей (ADMIN_TELEGRAM_ID, несколько через запятую).
 */
class TelegramAdminNotifier
{
    /**
     * @param  array<int,array<int,array{text:string,callback_data:string}>>|null  $inlineKeyboard
     * @return list<string> chat_id получателей, кому сообщение реально ушло
     */
    public function notifyAdmins(string $text, ?array $inlineKeyboard = null): array
    {
        return $this->notifyRecipients($this->adminChatIds(), $text, $inlineKeyboard);
    }

    /**
     * H3393: отправка явному списку chat_id (подсказки куратору конкретного
     * аккаунта поддержки). Тот же бот, тот же формат; пустой список — no-op.
     *
     * @param  list<string>  $chatIds
     * @param  array<int,array<int,array{text:string,callback_data:string}>>|null  $inlineKeyboard
     * @return list<string> кому реально ушло
     */
    public function notifyRecipients(array $chatIds, string $text, ?array $inlineKeyboard = null): array
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '' || $chatIds === []) {
            return [];
        }

        $delivered = [];
        foreach ($chatIds as $chatId) {
            if ($this->send($token, (string) $chatId, $text, $inlineKeyboard)) {
                $delivered[] = (string) $chatId;
            }
        }

        return $delivered;
    }

    public function send(string $token, string $chatId, string $text, ?array $inlineKeyboard = null): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

        if (! $response->successful()) {
            Log::error('Telegram admin notifier error', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Ответ на нажатие inline-кнопки (убирает «часики» на кнопке у клиента).
     */
    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return;
        }

        Http::post("https://api.telegram.org/bot{$token}/answerCallbackQuery", array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text,
        ]));
    }

    /** @return list<string> */
    public function adminChatIds(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.telegram.admin_id')),
        )));
    }
}
