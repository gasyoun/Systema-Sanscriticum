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
     * Сеть до api.telegram.org не должна держать FPM-воркер: при недоступном
     * Telegram connect обрывается за 2 с (обычный прод-случай — DNS отдаёт
     * недоступные IP), весь вызов ограничен 5 с. Алерт админу не срочный,
     * поэтому таймауты жёстче, чем в очередях (connect 5 / total 15).
     */
    private const CONNECT_TIMEOUT_SECONDS = 2;

    private const TIMEOUT_SECONDS = 5;

    /**
     * Оборвалась ли СЕТЬ на последнем send(). В отличие от HTTP-ошибки
     * (не-2xx), сетевой сбой означает, что все получатели — за одним и тем же
     * api.telegram.org — не получат сообщение, и повторять цикл бессмысленно.
     */
    private bool $lastSendWasNetworkFailure = false;

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
     * Сетевой сбой прерывает цикл: получателей может быть двое и больше, а
     * api.telegram.org у них один — иначе бюджет таймаута умножается на число
     * админов (2 × 5 с = те же 10 с, что держали воркер в инциденте).
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

                continue;
            }

            if ($this->lastSendWasNetworkFailure) {
                break;
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

        try {
            $this->lastSendWasNetworkFailure = false;
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        } catch (\Throwable $e) {
            $this->lastSendWasNetworkFailure = true;

            // Этот вызов исполняется СИНХРОННО внутри запроса логина
            // (неудачный вход → LogFailedAuthentication → AccessAttemptLogger).
            // Пока исключение летело наружу, недоступность Telegram превращала
            // «неверный пароль» в 500 и держала воркер ~10 с на каждую попытку.
            // Алерт — побочный эффект: его провал не имеет права ломать вход.
            //
            // В лог идёт санитизированный текст ошибки (cURL подставляет в него
            // URL вместе с токеном бота) и длина алерта вместо самого текста
            // (в нём email студента).
            Log::warning('Telegram admin notifier: отправка не удалась', [
                'chat_id' => $chatId,
                'error' => $this->sanitizeError($e->getMessage()),
                'text_length' => mb_strlen($text),
            ]);

            return false;
        }

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

    /**
     * Токен бота нельзя пускать в лог: Laravel/curl кладут в текст ошибки
     * соединения весь URL, а в нём `https://api.telegram.org/bot<digits>:<token>/sendMessage`.
     */
    private function sanitizeError(string $message): string
    {
        return (string) preg_replace('#bot\d+:[A-Za-z0-9_\-]+#i', 'bot[redacted]', $message);
    }
}
