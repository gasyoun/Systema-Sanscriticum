<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\MarketingSetting;
use App\Services\Messaging\Contracts\DeliveryChannel;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class TelegramDeliveryChannel implements DeliveryChannel
{
    private string $token;

    private string $botUsername;

    public function __construct()
    {
        $settings = MarketingSetting::cached();
        $this->token = (string) ($settings?->tg_bot_token ?? '');
        $this->botUsername = (string) ($settings?->tg_bot_username ?? '');
    }

    public function name(): string
    {
        return 'telegram';
    }

    /**
     * Возвращает клон канала с креденшелами конкретного бота лендинга.
     * Нужно для архитектуры «свой бот на лендинг»: магнит отдаётся тем ботом,
     * с которым юзер реально разговаривает, а не глобальным из MarketingSetting.
     */
    public function usingCredentials(?string $token, ?string $username): self
    {
        $clone = clone $this;

        if (! empty($token)) {
            $clone->token = $token;
        }
        if ($username !== null) {
            $clone->botUsername = $username;
        }

        return $clone;
    }

    public function buildDeepLink(string $token): string
    {
        return "https://t.me/{$this->botUsername}?start={$token}";
    }

    public function sendDocument(string $userIdInChannel, string $filePath, string $caption, ?string $displayName = null): void
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Telegram sendDocument: cannot open file {$filePath}");
        }

        try {
            $response = Http::attach('document', $handle, $displayName ?: basename($filePath))
                ->post("https://api.telegram.org/bot{$this->token}/sendDocument", [
                    'chat_id' => $userIdInChannel,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);

            if (! $response->successful() || ! ($response->json('ok') ?? false)) {
                Log::error('Telegram sendDocument failed', [
                    'chat_id' => $userIdInChannel,
                    'response' => $response->json(),
                ]);
                throw new RuntimeException('Telegram sendDocument error: '.$response->body());
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function sendMessage(string $userIdInChannel, string $text): void
    {
        if ($this->token === '' || $userIdInChannel === '') {
            throw new RuntimeException('Telegram sendMessage: token or chat_id is empty');
        }

        // Идемпотентность (TelegramSendGuard): ретрай джоба после потерянного
        // ответа Telegram не должен дать вторую копию лиду/гостю. Подавленная
        // отправка возвращает управление как успех — ретрай-цепочка спокойно
        // завершается. Детерминированный отказ Telegram ниже бросает исключение
        // как раньше (доставки не было, ретрай уместен).
        if (! TelegramSendGuard::claim($userIdInChannel, $text)) {
            Log::info('TelegramDeliveryChannel: identical message already sent, duplicate suppressed', [
                'chat_id' => $userIdInChannel,
                'dedup_key' => TelegramSendGuard::key($userIdInChannel, $text),
            ]);

            return;
        }

        $response = Http::post("https://api.telegram.org/bot{$this->token}/sendMessage", [
            'chat_id' => $userIdInChannel,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::error('Telegram sendMessage failed', [
                'chat_id' => $userIdInChannel,
                'response' => $response->json(),
            ]);
            throw new RuntimeException('Telegram sendMessage error: '.$response->body());
        }
    }

    /**
     * @param  list<string>  $allowedUpdates  Боту с n8n-анкетой нужны и callback_query
     *                                        (inline-кнопки), иначе они молча не работают.
     * @param  string|null  $certificate  PEM входного узла, если его сертификат
     *                                    самоподписанный: Telegram примет такой
     *                                    вебхук только вместе с загруженным файлом.
     */
    public function setWebhook(string $url, string $secret, array $allowedUpdates = ['message'], ?string $certificate = null): void
    {
        $endpoint = "https://api.telegram.org/bot{$this->token}/setWebhook";
        $fields = [
            'url' => $url,
            'secret_token' => $secret,
            'max_connections' => 40,
        ];

        if ($certificate === null) {
            $response = Http::post($endpoint, $fields + ['allowed_updates' => $allowedUpdates]);
        } else {
            // В multipart Laravel разворачивает вложенный массив в повторяющиеся
            // части `allowed_updates[]`, а Bot API их не разбирает — ему нужна
            // JSON-строка. В обычном (JSON) запросе, наоборот, годится массив.
            $response = Http::attach('certificate', $certificate, 'tg-webhook.pem')
                ->post($endpoint, $fields + ['allowed_updates' => json_encode($allowedUpdates)]);
        }

        if (! ($response->json('ok') ?? false)) {
            throw new RuntimeException('Telegram setWebhook error: '.$response->body());
        }
    }

    /**
     * Снимает вебхук. Обязательный шаг перед long polling: пока вебхук
     * зарегистрирован, getUpdates отвечает 409 Conflict — Telegram отдаёт
     * апдейты ровно одним способом.
     */
    public function deleteWebhook(): void
    {
        $response = Http::post("https://api.telegram.org/bot{$this->token}/deleteWebhook");

        if (! ($response->json('ok') ?? false)) {
            throw new RuntimeException('Telegram deleteWebhook error: '.$response->body());
        }
    }

    /**
     * @return array<string, mixed> тело result из getWebhookInfo
     */
    public function getWebhookInfo(): array
    {
        $response = Http::timeout(15)->get("https://api.telegram.org/bot{$this->token}/getWebhookInfo");

        if (! ($response->json('ok') ?? false)) {
            throw new RuntimeException('Telegram getWebhookInfo error: '.$response->body());
        }

        return (array) $response->json('result', []);
    }

    /**
     * Long polling: держит соединение до $timeout секунд, пока Telegram не
     * отдаст апдейты. Клиентский таймаут заведомо больше серверного, иначе
     * запрос рвётся раньше, чем приходит ответ, и апдейты кажутся потерянными.
     *
     * @param  array<int, string>  $allowedUpdates
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout = 50, array $allowedUpdates = ['message']): array
    {
        $response = Http::timeout($timeout + 15)
            ->post("https://api.telegram.org/bot{$this->token}/getUpdates", [
                'offset' => $offset,
                'timeout' => $timeout,
                'allowed_updates' => $allowedUpdates,
            ]);

        if (! ($response->json('ok') ?? false)) {
            throw new RuntimeException('Telegram getUpdates error: '.$response->body());
        }

        return (array) $response->json('result', []);
    }
}
