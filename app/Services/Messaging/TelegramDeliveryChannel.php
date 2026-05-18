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

    public function buildDeepLink(string $token): string
    {
        return "https://t.me/{$this->botUsername}?start={$token}";
    }

    public function sendDocument(string $userIdInChannel, string $filePath, string $caption): void
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Telegram sendDocument: cannot open file {$filePath}");
        }

        try {
            $response = Http::attach('document', $handle, basename($filePath))
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

    public function setWebhook(string $url, string $secret): void
    {
        $response = Http::post("https://api.telegram.org/bot{$this->token}/setWebhook", [
            'url' => $url,
            'secret_token' => $secret,
            'max_connections' => 40,
            'allowed_updates' => ['message'],
        ]);

        if (! ($response->json('ok') ?? false)) {
            throw new RuntimeException('Telegram setWebhook error: '.$response->body());
        }
    }
}
