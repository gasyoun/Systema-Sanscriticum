<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'started_at',
        'ended_at',
        'is_active',
        'enabled_packs',
        'rewards_config',
        // H3297: DB-driven decay-оверрайд (season:open true / season:close false).
        'decay_enabled',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_active' => 'boolean',
        'enabled_packs' => 'array',
        'rewards_config' => 'array',
        'decay_enabled' => 'boolean',
    ];

    public function leaderboardCache(): HasMany
    {
        return $this->hasMany(SeasonLeaderboardCache::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(SeasonReward::class);
    }

    /**
     * Проверяет, есть ли активный сезон в данный момент.
     */
    public static function isActive(): bool
    {
        return self::where('is_active', true)->exists();
    }

    /**
     * Возвращает текущий активный сезон.
     */
    public static function current(): ?Season
    {
        return self::where('is_active', true)->first();
    }
}
