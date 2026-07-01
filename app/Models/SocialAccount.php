<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory;

    /**
     * Канонические имена провайдеров внешней идентичности. `social_accounts`
     * (provider, provider_id, user_id) — единственный целевой стор консолидации
     * (см. docs/support-identity.md). VK-бот и VK-OAuth делят один namespace
     * VK-user-id → общий провайдер `vkontakte`, а не отдельный `vk`.
     */
    public const PROVIDER_TELEGRAM = 'telegram';

    public const PROVIDER_VK = 'vkontakte';

    public const PROVIDER_MAX = 'max';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
