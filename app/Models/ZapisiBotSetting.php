<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * H164 (D8): singleton settings row for the @zapisi_ORSbot class-booking bot
 * integration. Mirrors MarketingSetting's one-row + Cache::forever() pattern
 * and its 'encrypted' cast approach for the bot token / webhook secret — a
 * DB dump leak must not hand an attacker a forgeable webhook or bot API access.
 */
class ZapisiBotSetting extends Model
{
    private const CACHE_KEY = 'zapisi_bot_setting.singleton';

    protected $fillable = [
        'chat_id',
        'bot_username',
        'bot_token',
        'webhook_secret',
    ];

    protected $casts = [
        'bot_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
    ];

    public static function cached(): ?self
    {
        if ($cached = Cache::get(self::CACHE_KEY)) {
            return $cached;
        }

        $settings = static::first();

        if ($settings !== null) {
            Cache::forever(self::CACHE_KEY, $settings);
        }

        return $settings;
    }

    public static function flushCached(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCached());
        static::deleted(fn () => self::flushCached());
    }
}
