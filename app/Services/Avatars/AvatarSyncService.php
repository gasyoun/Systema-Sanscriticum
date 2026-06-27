<?php

declare(strict_types=1);

namespace App\Services\Avatars;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Добор реальных аватарок студентов из Telegram и VK.
 *
 * Картинку всегда СКАЧИВАЕМ к себе в public-диск, а не храним внешний URL:
 *  - Telegram file-ссылка (api.telegram.org/file/bot...) живёт ~1 час;
 *  - VK CDN-ссылка со временем тоже протухает.
 *
 * Честные ограничения Telegram: getChat по приватному пользователю отдаёт фото
 * только если он запускал бота (привязанные — запускали) и не закрыл фото
 * настройками приватности. Иначе фото нет → остаются инициалы (это не ошибка).
 */
class AvatarSyncService
{
    /** Папка в public-диске, куда складываем аватарки. */
    private const DIR = 'avatars';

    /** Таймаут HTTP-запросов к API мессенджеров, сек. */
    private const TIMEOUT = 10;

    /**
     * Подтянуть аватарку из Telegram через getChat → getFile → download.
     * Возвращает true, если фото скачано и сохранено.
     */
    public function syncTelegram(User $user): bool
    {
        if (! $user->telegram_id) {
            return false;
        }

        $token = config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token');

        if (! $token) {
            return false;
        }

        try {
            // 1. getChat → photo.big_file_id (для лички chat_id == user_id)
            $chat = Http::timeout(self::TIMEOUT)->get(
                "https://api.telegram.org/bot{$token}/getChat",
                ['chat_id' => $user->telegram_id],
            );

            if (! $chat->successful() || ! $chat->json('ok')) {
                return false;
            }

            $fileId = $chat->json('result.photo.big_file_id')
                ?? $chat->json('result.photo.small_file_id');

            if (! $fileId) {
                // Достучались, но фото нет (приватность/нет аватарки) — фиксируем
                // попытку, чтобы периодический прогон не дёргал каждый раз.
                $this->markSynced($user);

                return false;
            }

            // 2. getFile → file_path
            $file = Http::timeout(self::TIMEOUT)->get(
                "https://api.telegram.org/bot{$token}/getFile",
                ['file_id' => $fileId],
            );

            $filePath = $file->json('result.file_path');
            if (! $file->successful() || ! $filePath) {
                return false;
            }

            // 3. Скачиваем сам файл (ссылка короткоживущая)
            $binary = Http::timeout(self::TIMEOUT)
                ->get("https://api.telegram.org/file/bot{$token}/{$filePath}");

            if (! $binary->successful()) {
                return false;
            }

            return $this->storeDownloadedImage($user, $binary->body());
        } catch (\Throwable $e) {
            Log::warning('AvatarSync TG failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Подтянуть аватарку из VK через users.get (photo_200).
     * Возвращает true, если фото скачано и сохранено.
     */
    public function syncVk(User $user): bool
    {
        if (empty($user->vk_id)) {
            return false;
        }

        $token = config('services.vk.bot_token');
        if (! $token) {
            return false;
        }

        try {
            $resp = Http::timeout(self::TIMEOUT)->get('https://api.vk.com/method/users.get', [
                'user_ids' => $user->vk_id,
                'fields' => 'photo_200,has_photo',
                'access_token' => $token,
                'v' => '5.199',
            ]);

            $person = $resp->json('response.0');
            if (! is_array($person)) {
                return false;
            }

            // has_photo=0 → у человека нет аватарки, photo_200 вернёт заглушку-камеру.
            $photoUrl = $person['photo_200'] ?? null;
            if (($person['has_photo'] ?? 1) === 0 || ! $photoUrl) {
                $this->markSynced($user);

                return false;
            }

            $binary = Http::timeout(self::TIMEOUT)->get($photoUrl);
            if (! $binary->successful()) {
                return false;
            }

            return $this->storeDownloadedImage($user, $binary->body());
        } catch (\Throwable $e) {
            Log::warning('AvatarSync VK failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Синхронизировать из любого доступного источника: сначала Telegram, при
     * неудаче — VK. Возвращает true, если хоть откуда-то взяли фото.
     */
    public function sync(User $user): bool
    {
        return $this->syncTelegram($user) || $this->syncVk($user);
    }

    /**
     * Сохранить скачанную картинку в public-диск и проставить avatar_path.
     * Перезаписывает прежний файл (путь стабильный — avatars/{id}.jpg).
     */
    private function storeDownloadedImage(User $user, string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        $path = self::DIR.'/'.$user->id.'.jpg';
        Storage::disk('public')->put($path, $binary);

        $user->forceFill([
            'avatar_path' => $path,
            'avatar_synced_at' => now(),
        ])->save();

        return true;
    }

    /** Зафиксировать факт попытки синхронизации (фото нет) для троттлинга. */
    private function markSynced(User $user): void
    {
        $user->forceFill(['avatar_synced_at' => now()])->save();
    }
}
