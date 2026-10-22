<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\VkUserToken;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Жизненный цикл VK ID user-токена для кросспостинга календаря (22-10-2026).
 *
 * Токен живёт час; refresh по id.vk.com/oauth2/auth ротирует ОБА токена —
 * старый refresh_token после этого мёртв. Поэтому у пары один владелец:
 * после import() Mac-агент vk-token-refresh должен быть выключен, иначе две
 * стороны будут по очереди инвалидировать друг друга.
 *
 * Право смотрим по полю scope из ответа OAuth: account.getAppPermissions для
 * VK ID-токенов отдаёт неверный битмаск (проверено 22-10-2026).
 */
class VkTokenManager
{
    private const OAUTH_URL = 'https://id.vk.com/oauth2/auth';

    /** Обновляем, когда до истечения осталось меньше этого. */
    private const REFRESH_AHEAD_MINUTES = 35;

    public function __construct(private readonly TelegramAdminNotifier $notifier) {}

    /**
     * Ключи в формате vk_poll.env: VK_APP_ID, VK_ACCESS_TOKEN, VK_REFRESH_TOKEN,
     * необязательно VK_DEVICE_ID. Сразу делает refresh — прод становится
     * владельцем пары, а импортированный access_token проверяется делом.
     *
     * @param  array<string, string>  $vars
     */
    public function import(array $vars): VkUserToken
    {
        $clientId = trim($vars['VK_APP_ID'] ?? '');
        $access = trim($vars['VK_ACCESS_TOKEN'] ?? '');
        $refresh = trim($vars['VK_REFRESH_TOKEN'] ?? '');
        if ($clientId === '' || $refresh === '') {
            throw new RuntimeException('Нужны VK_APP_ID и VK_REFRESH_TOKEN.');
        }

        VkUserToken::query()->delete();
        $token = VkUserToken::create([
            'client_id' => $clientId,
            'access_token' => $access !== '' ? $access : 'pending',
            'refresh_token' => $refresh,
            'device_id' => trim($vars['VK_DEVICE_ID'] ?? '') ?: null,
            'expires_at' => now(),
        ]);

        $this->refresh($token);

        return $token->fresh();
    }

    /** Обновить, если пора. true — токен действующий после вызова. */
    public function refreshIfNeeded(): bool
    {
        $token = VkUserToken::current();
        if ($token === null) {
            return false;
        }
        if ($token->expires_at !== null && $token->expires_at->gt(now()->addMinutes(self::REFRESH_AHEAD_MINUTES))) {
            return true;
        }

        try {
            $this->refresh($token);

            return true;
        } catch (RuntimeException $e) {
            $firstFailure = $token->last_error === null;
            $token->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            Log::error('VkTokenManager: refresh не удался', ['error' => $e->getMessage()]);
            if ($firstFailure) {
                $left = $token->expires_at?->isFuture() ? (int) now()->diffInMinutes($token->expires_at) : 0;
                $this->notifier->notifyAdmins(
                    "⚠️ VK-токен кросспостинга не обновился: {$e->getMessage()}\n"
                    .($left > 0 ? "Текущий проживёт ещё ~{$left} мин." : 'Текущий уже истёк — VK-публикации стоят.')
                    ."\nЕсли не пройдёт на следующем тике — нужна переавторизация и vk:token-import."
                );
            }

            return $token->expires_at?->isFuture() ?? false;
        }
    }

    public function refresh(VkUserToken $token): void
    {
        $response = Http::timeout(30)->asForm()->post(self::OAUTH_URL, array_filter([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $token->refresh_token,
            'client_id' => $token->client_id,
            'device_id' => $token->device_id,
            'state' => Str::random(32),
        ], static fn ($v): bool => $v !== null && $v !== ''));

        $json = $response->json() ?? [];
        if (! $response->successful() || blank($json['access_token'] ?? null)) {
            throw new RuntimeException('id.vk.com: '.($json['error_description'] ?? $json['error'] ?? 'HTTP '.$response->status()));
        }

        $token->forceFill([
            'access_token' => $json['access_token'],
            'refresh_token' => $json['refresh_token'] ?? $token->refresh_token,
            'scope' => isset($json['scope']) ? (string) $json['scope'] : $token->scope,
            'vk_user_id' => isset($json['user_id']) ? (int) $json['user_id'] : $token->vk_user_id,
            'expires_at' => now()->addSeconds((int) ($json['expires_in'] ?? 3600)),
            'refreshed_at' => now(),
            'last_error' => null,
        ])->save();
    }
}
