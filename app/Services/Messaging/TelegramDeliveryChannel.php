<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\MarketingSetting;
use App\Services\Messaging\Contracts\DeliveryChannel;
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
     */
    public function setWebhook(string $url, string $secret, array $allowedUpdates = ['message']): void
    {
        $response = Http::post("https://api.telegram.org/bot{$this->token}/setWebhook", [
            'url' => $url,
            'secret_token' => $secret,
            'max_connections' => 40,
            'allowed_updates' => $allowedUpdates,
        ]);

        if (! ($response->json('ok') ?? false)) {
            throw new RuntimeException('Telegram setWebhook error: '.$response->body());
        }
    }
}
