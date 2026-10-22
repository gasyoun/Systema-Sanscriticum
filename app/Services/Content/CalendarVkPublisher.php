<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use App\Models\VkUserToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Слот календаря → пост на стене VK-сообщества от имени сообщества.
 *
 * Токен — VK ID user-токен админа (vk_user_tokens, обновляет vk:token-refresh).
 * Гейт как у PublishVideoAnonsVkWallJob: group_id обязан стоять в
 * VK_PUBLISH_AUTHORIZED_GROUPS, иначе отказ без повторов.
 *
 * Вложение: фото — photos.getWallUploadServer → загрузка → saveWallPhoto,
 * видео — video.save → загрузка. Нет нужного права у токена или загрузка
 * сорвалась — пост уходит текстом, причина возвращается как warning
 * (деградация названа, а не молчит). Стена разметку не понимает — текст
 * уходит плоским (PostFormatter::toPlain).
 */
class CalendarVkPublisher
{
    private const VK_API = 'https://api.vk.com/method';

    /**
     * @param  array<string, mixed>  $destination
     * @return array{external_id: string, permalink: string, warning: ?string}
     */
    public function publish(ContentCalendarSlot $slot, array $destination): array
    {
        $groupId = (int) ($destination['group_id'] ?? 0);
        $authorized = array_map('intval', (array) config('video-anons.vk.authorized_group_ids', []));
        if ($groupId <= 0 || ! in_array($groupId, $authorized, true)) {
            throw CalendarPublishException::permanent(
                "vk: группа {$groupId} не входит в VK_PUBLISH_AUTHORIZED_GROUPS (гейт публикации)"
            );
        }

        $tokenRow = VkUserToken::current();
        $token = VkUserToken::activeAccessToken();
        if ($tokenRow === null || $token === null) {
            throw new CalendarPublishException('vk: нет действующего VK-токена (vk:token-import / vk:token-refresh)');
        }

        [$attachments, $warning] = $this->attachment($slot, $tokenRow, $token, $groupId);

        $response = $this->call('wall.post', $token, [
            'owner_id' => -$groupId,
            'from_group' => 1,
            'message' => PostFormatter::toPlain($slot->body),
            'attachments' => implode(',', $attachments),
        ]);

        $postId = $response['post_id'] ?? null;
        if ($postId === null) {
            throw new CalendarPublishException('vk: wall.post — '.($response['error'] ?? 'нет post_id в ответе'));
        }

        return [
            'external_id' => (string) $postId,
            'permalink' => "https://vk.com/wall-{$groupId}_{$postId}",
            'warning' => $warning,
        ];
    }

    /** @return array{0: list<string>, 1: ?string} */
    private function attachment(ContentCalendarSlot $slot, VkUserToken $tokenRow, string $token, int $groupId): array
    {
        $type = $slot->mediaType();
        if ($type === null) {
            return [[], null];
        }

        $scope = $type === ContentCalendarSlot::MEDIA_PHOTO ? 'photos' : 'video';
        if (! $tokenRow->hasScope($scope)) {
            return [[], "VK: у токена нет права «{$scope}» — пост опубликован без вложения"];
        }

        try {
            $attachment = $type === ContentCalendarSlot::MEDIA_PHOTO
                ? $this->uploadPhoto($slot, $token, $groupId)
                : $this->uploadVideo($slot, $token, $groupId);
        } catch (Throwable $e) {
            Log::warning('CalendarVkPublisher: вложение не загрузилось — пост уходит текстом', [
                'calendar_slot_id' => $slot->id,
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);

            return [[], 'VK: вложение не загрузилось ('.$e->getMessage().') — пост опубликован текстом'];
        }

        return [[$attachment], null];
    }

    private function uploadPhoto(ContentCalendarSlot $slot, string $token, int $groupId): string
    {
        $server = $this->call('photos.getWallUploadServer', $token, ['group_id' => $groupId]);
        $uploadUrl = (string) ($server['upload_url'] ?? '');
        if ($uploadUrl === '') {
            throw new \RuntimeException('photos.getWallUploadServer: '.($server['error'] ?? 'нет upload_url'));
        }

        $uploaded = Http::timeout(120)
            ->attach('photo', $this->fileContents($slot), basename((string) $slot->media_path))
            ->post($uploadUrl)
            ->json() ?? [];
        if (blank($uploaded['photo'] ?? null) || ($uploaded['photo'] ?? '') === '[]') {
            throw new \RuntimeException('загрузка фото: пустой ответ upload-сервера');
        }

        $saved = $this->call('photos.saveWallPhoto', $token, [
            'group_id' => $groupId,
            'photo' => $uploaded['photo'],
            'server' => $uploaded['server'] ?? '',
            'hash' => $uploaded['hash'] ?? '',
        ]);
        $photo = $saved[0] ?? null;
        if (! is_array($photo) || ! isset($photo['owner_id'], $photo['id'])) {
            throw new \RuntimeException('photos.saveWallPhoto: '.($saved['error'] ?? 'нет id фото'));
        }

        return 'photo'.$photo['owner_id'].'_'.$photo['id'];
    }

    private function uploadVideo(ContentCalendarSlot $slot, string $token, int $groupId): string
    {
        $save = $this->call('video.save', $token, [
            'group_id' => $groupId,
            'name' => mb_substr(PostFormatter::toPlain($slot->title ?: $slot->body), 0, 128),
            'wallpost' => 0,
        ]);
        $uploadUrl = (string) ($save['upload_url'] ?? '');
        if ($uploadUrl === '' || ! isset($save['owner_id'], $save['video_id'])) {
            throw new \RuntimeException('video.save: '.($save['error'] ?? 'нет upload_url'));
        }

        $upload = Http::timeout(300)
            ->attach('video_file', $this->fileContents($slot), basename((string) $slot->media_path))
            ->post($uploadUrl);
        if (! $upload->successful()) {
            throw new \RuntimeException('загрузка видео: HTTP '.$upload->status());
        }

        return 'video'.$save['owner_id'].'_'.$save['video_id'];
    }

    private function fileContents(ContentCalendarSlot $slot): string
    {
        $disk = Storage::disk(ContentCalendarSlot::MEDIA_DISK);
        $path = (string) $slot->media_path;
        if (! $disk->exists($path)) {
            throw new \RuntimeException("файл вложения не найден ({$path})");
        }

        return (string) $disk->get($path);
    }

    /**
     * VK отвечает на ошибки HTTP 200 с полем error — разворачиваем в
     * ['error' => '<code> <msg>'], чтобы вызывающий не принял отказ за успех.
     * Токен — в теле формы, не в query (не попадает в логи прокси).
     *
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     */
    private function call(string $method, string $token, array $params): array
    {
        $response = Http::timeout(30)->asForm()->post(self::VK_API.'/'.$method, $params + [
            'access_token' => $token,
            'v' => (string) config('content_calendar.vk_api_version', '5.199'),
        ]);

        $json = $response->json() ?? [];
        if (isset($json['error'])) {
            return ['error' => ($json['error']['error_code'] ?? '?').' '.($json['error']['error_msg'] ?? '')];
        }
        if (! $response->successful()) {
            return ['error' => 'HTTP '.$response->status()];
        }

        return (array) ($json['response'] ?? []);
    }
}
