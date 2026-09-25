<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Support\TelegramTransport;
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
     * Сеть до api.telegram.org не должна держать FPM-воркер: таймауты и
     * санитизация живут в общем контракте App\Support\TelegramTransport
     * (connect 2 с / всего 5 с) — здесь только семантика алертов.
     *
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
            $response = TelegramTransport::client()
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
                'error' => TelegramTransport::sanitize($e->getMessage()),
                'text_length' => mb_strlen($text),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('Telegram admin notifier error', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => TelegramTransport::sanitize($response->body()),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Настроена ли отправка админам: есть и токен бота, и получатели.
     * Нужен вызывающему коду, чтобы отличить «сбой доставки» (получатели есть,
     * сообщение не ушло) от «отправлять некуда и нечем» (тихий no-op).
     */
    public function configured(): bool
    {
        return (string) config('services.telegram.bot_token') !== '' && $this->adminChatIds() !== [];
    }

    /**
     * Ответ на нажатие inline-кнопки (убирает «часики» на кнопке у клиента).
     *
     * Вызывается из тела вебхука: пока вызов бросал исключение, недоступный
     * Telegram отвечал Telegram'у 500, тот повторял апдейт — и каждый повтор
     * снова держал воркер. Ответ на callback — косметика, не повод падать.
     */
    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return;
        }

        TelegramTransport::post(
            "https://api.telegram.org/bot{$token}/answerCallbackQuery",
            array_filter([
                'callback_query_id' => $callbackId,
                'text' => $text,
            ]),
            'Telegram answerCallbackQuery',
            ['callback_id_length' => mb_strlen($callbackId)],
        );
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
