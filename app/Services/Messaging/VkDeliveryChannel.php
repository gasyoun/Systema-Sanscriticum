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
        $settings = MarketingSetting::cached();
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

    public function sendDocument(string $userIdInChannel, string $filePath, string $caption, ?string $displayName = null): void
    {
        $finalName = $displayName ?: basename($filePath);

        $uploadUrl = Http::asForm()->post('https://api.vk.com/method/docs.getMessagesUploadServer', [
            'type' => 'doc',
            'peer_id' => $userIdInChannel,
            'access_token' => $this->accessToken,
            'v' => self::API_VERSION,
        ])->json('response.upload_url');

        if (! $uploadUrl) {
            throw new RuntimeException('VK: не удалось получить upload URL');
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("VK sendDocument: cannot open file {$filePath}");
        }

        try {
            $uploaded = Http::attach('file', $handle, $finalName)
                ->post($uploadUrl)
                ->json();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (empty($uploaded['file'])) {
            throw new RuntimeException('VK: ошибка загрузки файла');
        }

        $saved = Http::asForm()->post('https://api.vk.com/method/docs.save', [
            'file' => $uploaded['file'],
            'title' => $finalName,
            'access_token' => $this->accessToken,
            'v' => self::API_VERSION,
        ])->json('response');

        $docId = $saved[0]['id'] ?? ($saved['doc']['id'] ?? null);
        $ownerId = $saved[0]['owner_id'] ?? ($saved['doc']['owner_id'] ?? null);

        if (! $docId || ! $ownerId) {
            throw new RuntimeException('VK: не удалось сохранить документ: '.json_encode($saved));
        }

        // random_id используется VK для дедупа — полный 32-битный диапазон даёт ничтожный риск коллизий.
        // peer_id — рекомендованный параметр (user_id deprecated с 5.80).
        $response = Http::asForm()->post('https://api.vk.com/method/messages.send', [
            'peer_id' => $userIdInChannel,
            'random_id' => random_int(1, 2_147_483_647),
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
