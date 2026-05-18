<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\MarketingSetting;
use App\Services\Messaging\Contracts\DeliveryChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Max Bot API: https://dev.max.ru/docs/
 * Внимание: user_id в Max строковый, не bigint как в Telegram.
 */
final class MaxDeliveryChannel implements DeliveryChannel
{
    private const API_BASE = 'https://botapi.max.ru';

    private string $token;

    private string $botUsername;

    public function __construct()
    {
        $settings = MarketingSetting::cached();
        $this->token = (string) ($settings?->max_bot_token ?? '');
        $this->botUsername = (string) ($settings?->max_bot_username ?? '');
    }

    public function name(): string
    {
        return 'max';
    }

    public function buildDeepLink(string $token): string
    {
        return "https://max.ru/@{$this->botUsername}?start={$token}";
    }

    public function sendDocument(string $userIdInChannel, string $filePath, string $caption): void
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Max sendDocument: cannot open file {$filePath}");
        }

        try {
            $uploaded = Http::withToken($this->token)
                ->attach('content', $handle, basename($filePath))
                ->post(self::API_BASE.'/uploads', ['type' => 'file'])
                ->json();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $uploadToken = $uploaded['token'] ?? null;

        if (! $uploadToken) {
            Log::error('Max upload failed', ['response' => $uploaded]);
            throw new RuntimeException('Max: не удалось загрузить файл');
        }

        $response = Http::withToken($this->token)
            ->post(self::API_BASE.'/messages', [
                // user_id строковый — передаём как есть, чтобы не терять идентификаторы за пределами int.
                'recipient' => ['user_id' => $userIdInChannel],
                'attachments' => [
                    [
                        'type' => 'file',
                        'payload' => ['token' => $uploadToken],
                    ],
                ],
                'text' => $caption,
            ])
            ->json();

        if (! empty($response['error'])) {
            Log::error('Max sendDocument failed', ['response' => $response]);
            throw new RuntimeException('Max message error: '.($response['message'] ?? 'unknown'));
        }
    }

    public function setWebhook(string $url): void
    {
        $response = Http::withToken($this->token)
            ->post(self::API_BASE.'/subscriptions', ['url' => $url])
            ->json();

        if (($response['result'] ?? '') !== 'ok') {
            throw new RuntimeException('Max setWebhook error: '.json_encode($response));
        }
    }
}
