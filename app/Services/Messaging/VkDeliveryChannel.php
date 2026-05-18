<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\MarketingSetting;
use App\Services\Messaging\Contracts\DeliveryChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class VkDeliveryChannel implements DeliveryChannel
{
    private const API_VERSION = '5.199';

    private string $groupScreenName;

    private string $accessToken;

    public function __construct()
    {
        $settings = MarketingSetting::first();
        $this->groupScreenName = (string) ($settings?->vk_group_screen_name ?? '');
        $this->accessToken = (string) ($settings?->vk_access_token ?? '');
    }

    public function name(): string
    {
        return 'vk';
    }

    public function buildDeepLink(string $token): string
    {
        // ref передаётся VK сообществу как payload первого входящего сообщения.
        return "https://vk.me/{$this->groupScreenName}?ref={$token}";
    }

    public function sendDocument(string $userIdInChannel, string $filePath, string $caption): void
    {
        $uploadUrl = Http::asForm()->post('https://api.vk.com/method/docs.getMessagesUploadServer', [
            'type' => 'doc',
            'peer_id' => $userIdInChannel,
            'access_token' => $this->accessToken,
            'v' => self::API_VERSION,
        ])->json('response.upload_url');

        if (! $uploadUrl) {
            throw new RuntimeException('VK: не удалось получить upload URL');
        }

        $uploaded = Http::attach('file', fopen($filePath, 'r'), basename($filePath))
            ->post($uploadUrl)
            ->json();

        if (empty($uploaded['file'])) {
            throw new RuntimeException('VK: ошибка загрузки файла');
        }

        $saved = Http::asForm()->post('https://api.vk.com/method/docs.save', [
            'file' => $uploaded['file'],
            'title' => basename($filePath),
            'access_token' => $this->accessToken,
            'v' => self::API_VERSION,
        ])->json('response');

        $docId = $saved[0]['id'] ?? ($saved['doc']['id'] ?? null);
        $ownerId = $saved[0]['owner_id'] ?? ($saved['doc']['owner_id'] ?? null);

        if (! $docId || ! $ownerId) {
            throw new RuntimeException('VK: не удалось сохранить документ: '.json_encode($saved));
        }

        $response = Http::asForm()->post('https://api.vk.com/method/messages.send', [
            'user_id' => $userIdInChannel,
            'random_id' => random_int(100000, 999999999),
            'message' => $caption,
            'attachment' => "doc{$ownerId}_{$docId}",
            'access_token' => $this->accessToken,
            'v' => self::API_VERSION,
        ])->json();

        if (! empty($response['error'])) {
            Log::error('VK messages.send failed', ['response' => $response, 'user_id' => $userIdInChannel]);
            throw new RuntimeException('VK messages.send error: '.json_encode($response['error']));
        }
    }
}
