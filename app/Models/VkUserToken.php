<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * VK ID user-токен админа сообществ (22-10-2026). Живёт час, при каждом
 * обновлении VK ротирует и refresh_token — поэтому владелец у пары ровно один
 * (прод, команда vk:token-refresh). Одна строка; секреты зашифрованы.
 */
class VkUserToken extends Model
{
    protected $fillable = [
        'client_id',
        'access_token',
        'refresh_token',
        'device_id',
        'scope',
        'vk_user_id',
        'expires_at',
        'refreshed_at',
        'last_error',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'refreshed_at' => 'datetime',
        'vk_user_id' => 'integer',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public static function current(): ?self
    {
        return self::query()->latest('id')->first();
    }

    /** Действующий access_token или null (нет записи / истёк). */
    public static function activeAccessToken(): ?string
    {
        $token = self::current();
        if ($token === null || blank($token->access_token)) {
            return null;
        }
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return null;
        }

        return (string) $token->access_token;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, preg_split('/[\s,]+/', (string) $this->scope) ?: [], true);
    }
}
